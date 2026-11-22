<?php

namespace Sellissimo\SzamlazzHu;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Sellissimo\Core\Registry;
use Sellissimo\Core\SettingsRegistry;
use Sellissimo\Core\Store;
use Sellissimo\SzamlazzHu\Automation\Workflow;
use Sellissimo\SzamlazzHu\Fiscal\Placement;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public const VIEW = 'view sellissimo invoices';

    public const ISSUE = 'issue sellissimo invoices';

    public const MANAGE = 'manage sellissimo invoice settings';

    protected $config = false;

    protected $viewNamespace = 'szamlazz';

    public function register()
    {
        $path = 'statamic.editions.addons.'.Settings::PACKAGE;
        if (config($path) === null) {
            config([$path => filter_var(env('SELLISSIMO_SZAMLAZZ_PRO', false), FILTER_VALIDATE_BOOLEAN) ? 'pro' : 'free']);
        }
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'szamlazz');
        Permission::group('sellissimo_szamlazz', 'Számlázz.hu', function () {
            foreach ([self::VIEW => 'view', self::ISSUE => 'issue_permission', self::MANAGE => 'manage'] as $ability => $label) {
                Permission::register($ability)->label(__('szamlazz::ui.'.$label));
            }
        });
        app(Registry::class)->orderPanel('szamlazz', self::VIEW, fn ($order) => ! app(Store::class)->hasTable('sz_settings') ? null : [
            'title' => 'Számlázz.hu', 'label' => __('szamlazz::ui.documents'),
            'url' => cp_route('szamlazz.order', $order->id),
        ]);
        app(SettingsRegistry::class)->section(
            'szamlazz', 'Számlázz.hu', __('szamlazz::ui.defaults').' · '.(app(Settings::class)->pro() ? 'Pro' : 'Free'),
            self::MANAGE, fn () => [], route: 'szamlazz.settings'
        );
        app(Placement::class)->register();
        app(Workflow::class)->register();
        Event::listen(CommandFinished::class, function ($event) {
            if ($event->command === 'config:cache' && ! $event->exitCode && app(Store::class)->hasTable('sz_settings')) {
                // config:cache boots a fresh app to write the cache. This event can run on the old app.
                $cached = require app()->getCachedConfigPath();
                config(['statamic.editions.addons.'.Settings::PACKAGE => data_get($cached, 'statamic.editions.addons.'.Settings::PACKAGE, 'free')]);
                app(Settings::class)->observeEdition();
            }
        });
    }

    protected function schedule(Schedule $schedule)
    {
        $schedule->call(function () {
            if (app(Store::class)->hasTable('sz_settings')) {
                app(Settings::class)->observeEdition();
                app(Workflow::class)->scan();
            }
        })->name('szamlazz:automate')->everyFiveMinutes()->withoutOverlapping();
        $schedule->call(fn () => app(Ledger::class)->reconcileDue())->name('szamlazz:recover')->everyFiveMinutes()->withoutOverlapping();
    }
}
