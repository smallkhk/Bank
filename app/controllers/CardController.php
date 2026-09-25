<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\CardService;
use App\Services\CreditCardService;
use App\Services\Money;

final class CardController extends Controller
{
    private function cid(): int
    {
        if (setting('cards_enabled') !== '1') {
            flash('error', 'Cards are not available at the moment.');
            redirect('/dashboard');
        }
        return Auth::customerId() ?? $this->forbidden();
    }

    private function ownCard(int $id): array
    {
        $card = CardService::find($id);
        if (!$card || (int) $card['customer_id'] !== $this->cid()) {
            $this->notFound();
        }
        return $card;
    }

    private function depositAccounts(int $cid): array
    {
        return Db::all("SELECT a.*, t.name AS type_name FROM accounts a JOIN account_types t ON t.id = a.account_type_id
                         WHERE a.customer_id = ? AND a.status = 'active' AND a.credit_limit = 0 ORDER BY a.id", [$cid]);
    }

    public function index(): void
    {
        $cid = $this->cid();
        $this->view('customer/cards', [
            'title' => 'Cards',
            'cards' => Db::all("SELECT c.*, p.name AS product_name, p.card_type, p.form_factor, a.balance, a.credit_limit, a.held_amount, a.account_number
                                  FROM cards c JOIN card_products p ON p.id = c.product_id LEFT JOIN accounts a ON a.id = c.account_id
                                 WHERE c.customer_id = ? AND c.status NOT IN ('rejected') ORDER BY FIELD(c.status,'active','frozen','pending','blocked','expired','cancelled'), c.id DESC", [$cid]),
            'products' => Db::all("SELECT * FROM card_products WHERE status = 'active' AND customer_requestable = 1"
                                  . (setting('credit_cards_enabled') === '1' ? '' : " AND card_type <> 'credit'") . ' ORDER BY card_type, name'),
            'accounts' => $this->depositAccounts($cid),
        ]);
    }

    public function request(): void
    {
        $cid = $this->cid();
        $this->attempt(fn () => CardService::request($cid, (int) input('product_id'), (int) input('account_id') ?: null, (int) Auth::id()), '/cards');
        flash('success', 'Your card request has been received and will be reviewed shortly.');
        redirect('/cards');
    }

    public function show(string $id): void
    {
        $card = $this->ownCard((int) $id);
        $credit = null;
        if ($card['card_type'] === 'credit' && $card['account_id']) {
            $acc = AccountService::find((int) $card['account_id']);
            $credit = [
                'account' => $acc, 'owed' => CreditCardService::owed($acc), 'available' => AccountService::available($acc),
                'statement' => CreditCardService::latestStatement((int) $acc['id']),
                'statements' => Db::all('SELECT * FROM credit_statements WHERE account_id = ? ORDER BY period_end DESC LIMIT 12', [$acc['id']]),
                'product' => Db::one('SELECT * FROM card_products WHERE id = ?', [$card['product_id']]),
            ];
            if ($credit['statement']) {
                $credit['paid_since'] = CreditCardService::paidSince($credit['statement']);
            }
        }
        $this->view('customer/card', [
            'title' => $card['product_name'], 'card' => $card, 'credit' => $credit,
            'accounts' => $this->depositAccounts((int) $card['customer_id']),
            'txs' => Db::all('SELECT * FROM card_transactions WHERE card_id = ? ORDER BY id DESC LIMIT 50', [$card['id']]),
        ]);
    }

    public function freeze(string $id): void
    {
        $card = $this->ownCard((int) $id);
        $to = $card['status'] === 'frozen' ? 'active' : 'frozen';
        $this->attempt(fn () => CardService::setStatus($card, $to, $to === 'frozen' ? 'Frozen by cardholder' : 'Unfrozen by cardholder'), '/cards/' . $card['id']);
        flash('success', $to === 'frozen' ? 'Card frozen. No new payments will be approved until you unfreeze it.' : 'Card unfrozen.');
        redirect('/cards/' . $card['id']);
    }

    public function controls(string $id): void
    {
        $card = $this->ownCard((int) $id);
        $limit = input('daily_limit') === '' ? null : Money::parse(input('daily_limit'));
        if (input('daily_limit') !== '' && $limit === null) {
            flash('error', 'Enter a valid daily limit.');
            redirect('/cards/' . $card['id']);
        }
        $this->attempt(fn () => CardService::updateControls($card, input('online') === '1', input('atm') === '1', input('international') === '1', $limit), '/cards/' . $card['id']);
        flash('success', 'Card settings saved.');
        redirect('/cards/' . $card['id']);
    }

    public function reportLost(string $id): void
    {
        $card = $this->ownCard((int) $id);
        $reason = in_array(input('reason'), ['Lost', 'Stolen', 'Damaged'], true) ? input('reason') : 'Lost';
        if (!password_verify((string) ($_POST['password'] ?? ''), Auth::user()['password_hash'])) {
            flash('error', 'Password confirmation failed.');
            redirect('/cards/' . $card['id']);
        }
        $newId = $this->attempt(fn () => CardService::replace($card, $reason . ' (reported by cardholder)', (int) Auth::id()), '/cards/' . $card['id']);
        flash('success', 'Your card has been blocked and a replacement has been requested.');
        redirect('/cards/' . $newId);
    }

    /** Reveal full card number and CVV after password re-authentication (JSON). */
    public function reveal(string $id): void
    {
        $card = $this->ownCard((int) $id);
        if (!in_array($card['status'], ['active', 'frozen'], true)) {
            json_response(['error' => 'Card details are not available for this card.'], 422);
        }
        $recent = (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'card.reveal_failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE", [Auth::id()]);
        if ($recent >= 5) {
            json_response(['error' => 'Too many attempts. Please try again later.'], 429);
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), Auth::user()['password_hash'])) {
            AuditService::log('card.reveal_failed', 'card', $card['id']);
            json_response(['error' => 'Incorrect password.'], 403);
        }
        json_response(CardService::reveal($card));
    }

    public function pay(string $id): void
    {
        $card = $this->ownCard((int) $id);
        $back = '/cards/' . $card['id'];
        $from = AccountService::find((int) input('from_account'));
        if (!$from || (int) $from['customer_id'] !== (int) $card['customer_id'] || $card['card_type'] !== 'credit') {
            $this->notFound();
        }
        $acc = AccountService::find((int) $card['account_id']);
        $amount = match (input('option')) {
            'full' => CreditCardService::owed($acc),
            'minimum' => (function () use ($acc) {
                $st = CreditCardService::latestStatement((int) $acc['id']);
                return $st ? max(0, (int) $st['minimum_payment'] - CreditCardService::paidSince($st)) : 0;
            })(),
            default => Money::parse(input('amount')) ?? 0,
        };
        $amount = min($amount, CreditCardService::owed($acc));
        $this->attempt(fn () => CreditCardService::pay((int) $from['id'], (int) $acc['id'], $amount, (int) Auth::id()), $back);
        flash('success', 'Payment of ' . money($amount) . ' received. Thank you.');
        redirect($back);
    }
}
