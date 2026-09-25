<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Account statements computed from the ledger (the source of truth). */
final class StatementService
{
    /**
     * @return array{opening:int, closing:int, credits:int, debits:int, from:string, to:string, entries:array}
     */
    public static function build(int $accountId, string $fromDate, string $toDate, ?int $limit = null): array
    {
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';
        $before = Db::value('SELECT balance_after FROM ledger_entries WHERE account_id = ? AND created_at < ? ORDER BY id DESC LIMIT 1', [$accountId, $from]);
        $opening = (int) ($before ?? 0);
        $closing = Db::value('SELECT balance_after FROM ledger_entries WHERE account_id = ? AND created_at <= ? ORDER BY id DESC LIMIT 1', [$accountId, $to]);
        $sums = Db::one("SELECT COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount END),0) AS credits,
                                COALESCE(SUM(CASE WHEN entry_type='debit' THEN amount END),0) AS debits
                           FROM ledger_entries WHERE account_id = ? AND created_at BETWEEN ? AND ?", [$accountId, $from, $to]);
        $entries = Db::all(
            'SELECT l.entry_type, l.amount, l.balance_after, l.created_at, t.reference, t.type, t.description
               FROM ledger_entries l JOIN transactions t ON t.id = l.transaction_id
              WHERE l.account_id = ? AND l.created_at BETWEEN ? AND ? ORDER BY l.id' . ($limit ? " LIMIT $limit" : ''),
            [$accountId, $from, $to]
        );
        return [
            'opening' => $opening, 'closing' => $closing === null ? $opening : (int) $closing,
            'credits' => (int) $sums['credits'], 'debits' => (int) $sums['debits'],
            'from' => $fromDate, 'to' => $toDate, 'entries' => $entries,
        ];
    }

    public static function pdf(array $account, array $st): string
    {
        $pdf = new PdfDocument();
        $brand = PdfDocument::hexToRgb((string) setting('color_primary'));
        $muted = [0.39, 0.44, 0.51];
        $cur = $account['currency'];
        $m = 40.0;
        $right = PdfDocument::W - $m;
        $page = 0;

        $header = function () use ($pdf, $brand, $muted, $account, $st, $m, $right, &$page) {
            $pdf->addPage();
            $page++;
            $pdf->rect(0, 0, PdfDocument::W, 70, $brand);
            $pdf->text($m, 32, bank_name(), 16, true, 'left', [1, 1, 1]);
            $pdf->text($m, 50, 'Account statement', 10, false, 'left', [0.85, 0.9, 0.95]);
            $pdf->text($right, 32, fmt_date($st['from'], 'M j, Y') . ' – ' . fmt_date($st['to'], 'M j, Y'), 10, true, 'right', [1, 1, 1]);
            $pdf->text($right, 50, 'Page ' . $page, 9, false, 'right', [0.85, 0.9, 0.95]);
            return 70.0;
        };
        $y = $header() + 30;

        // Customer & account block
        $pdf->text($m, $y, (string) $account['customer_name'], 12, true);
        $pdf->text($m, $y + 16, 'Account: ' . $account['account_number'] . '  ·  ' . $account['type_name'] . '  ·  ' . $cur, 10, false, 'left', $muted);
        $pdf->text($right, $y, 'Generated ' . fmt_date(now(), 'M j, Y g:i A'), 9, false, 'right', $muted);
        $y += 40;

        // Summary boxes
        $boxes = [['Opening balance', $st['opening']], ['Total credits', $st['credits']], ['Total debits', -$st['debits']], ['Closing balance', $st['closing']]];
        $bw = ($right - $m - 30) / 4;
        foreach ($boxes as $i => [$label, $amt]) {
            $x = $m + $i * ($bw + 10);
            $pdf->rect($x, $y, $bw, 48, [0.96, 0.97, 0.98]);
            $pdf->text($x + 10, $y + 18, $label, 8.5, false, 'left', $muted);
            $pdf->text($x + 10, $y + 36, Money::format($amt, $cur), 12, true);
        }
        $y += 72;

        $cols = ['date' => $m, 'desc' => $m + 70, 'ref' => $m + 255, 'debit' => $m + 385, 'credit' => $m + 450, 'bal' => $right];
        $tableHead = function (float $y) use ($pdf, $cols, $muted, $m, $right) {
            $pdf->text($cols['date'], $y, 'DATE', 8, true, 'left', $muted);
            $pdf->text($cols['desc'], $y, 'DESCRIPTION', 8, true, 'left', $muted);
            $pdf->text($cols['ref'], $y, 'REFERENCE', 8, true, 'left', $muted);
            $pdf->text($cols['debit'], $y, 'DEBIT', 8, true, 'right', $muted);
            $pdf->text($cols['credit'], $y, 'CREDIT', 8, true, 'right', $muted);
            $pdf->text($cols['bal'], $y, 'BALANCE', 8, true, 'right', $muted);
            $pdf->line($m, $y + 6, $right, $y + 6, 0.8);
            return $y + 22;
        };
        $y = $tableHead($y);
        if (!$st['entries']) {
            $pdf->text($m, $y, 'No transactions in this period.', 10, false, 'left', $muted);
        }
        foreach ($st['entries'] as $e) {
            if ($y > PdfDocument::H - 70) {
                $y = $tableHead($header() + 30);
            }
            $pdf->text($cols['date'], $y, fmt_date($e['created_at'], 'M j, Y'), 9);
            $pdf->text($cols['desc'], $y, PdfDocument::truncate((string) ($e['description'] ?: ucfirst($e['type'])), 175, 9), 9);
            $pdf->text($cols['ref'], $y, $e['reference'], 7.5, false, 'left', $muted);
            $amt = Money::format((int) $e['amount'], $cur);
            if ($e['entry_type'] === 'debit') {
                $pdf->text($cols['debit'], $y, $amt, 9, false, 'right', [0.7, 0.15, 0.12]);
            } else {
                $pdf->text($cols['credit'], $y, $amt, 9, false, 'right', [0.07, 0.48, 0.31]);
            }
            $pdf->text($cols['bal'], $y, Money::format((int) $e['balance_after'], $cur), 9, false, 'right');
            $pdf->line($m, $y + 7, $right, $y + 7, 0.3);
            $y += 20;
        }

        $foot = PdfDocument::H - 30;
        $pdf->text($m, $foot, bank_name() . (setting('address') ? ' · ' . setting('address') : '') . (setting('support_phone') ? ' · ' . setting('support_phone') : ''), 7.5, false, 'left', $muted);
        if (setting('sandbox_notice', '1') === '1') {
            $pdf->text($right, $foot, 'Simulated balances — not real money', 7.5, true, 'right', [0.6, 0.4, 0.0]);
        }
        return $pdf->output('Statement ' . $account['account_number']);
    }
}
