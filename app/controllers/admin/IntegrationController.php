<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\Integrations;

final class IntegrationController extends Controller
{
    public function index(): void
    {
        $rows = array_column(Db::all('SELECT * FROM integrations'), null, 'provider');
        $configs = [];
        foreach (Integrations::PROVIDERS as $key => $def) {
            if ($def['driver']) {
                try {
                    $configs[$key] = Integrations::config($key);
                } catch (\Throwable) {
                    $configs[$key] = []; // app.key changed or not yet set
                }
            }
        }
        $this->view('admin/integrations', [
            'title' => 'Integrations', 'rows' => $rows, 'configs' => $configs,
            'logs' => Db::all('SELECT * FROM integration_logs ORDER BY id DESC LIMIT 40'),
            'payments' => Db::all('SELECT g.*, u.full_name FROM gateway_payments g JOIN customers c ON c.id = g.customer_id JOIN users u ON u.id = c.user_id ORDER BY g.id DESC LIMIT 20'),
            'webhookUrl' => rtrim((string) config('app.url'), '/') . url('webhooks/stripe'),
            'keyOk' => strlen((string) config('app.key')) >= 32 && !str_contains((string) config('app.key'), 'CHANGE-ME'),
        ]);
    }

    public function save(string $provider): void
    {
        if (!isset(Integrations::PROVIDERS[$provider])) {
            $this->notFound();
        }
        $mode = input('mode') === 'live' ? 'live' : 'test';
        $this->attempt(fn () => Integrations::save($provider, (array) ($_POST['f'] ?? []), input('enabled') === '1', $mode, (int) Auth::id()), '/admin/integrations#' . $provider);
        flash('success', Integrations::PROVIDERS[$provider]['name'] . ' settings saved.');
        redirect('/admin/integrations#' . $provider);
    }

    public function test(string $provider): void
    {
        if (!isset(Integrations::PROVIDERS[$provider])) {
            $this->notFound();
        }
        $r = Integrations::test($provider);
        flash($r['ok'] ? 'success' : 'error', Integrations::PROVIDERS[$provider]['name'] . ': ' . $r['message']);
        redirect('/admin/integrations#' . $provider);
    }
}
