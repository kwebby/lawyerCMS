<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Providers;

use App\Auth\CrmUserProvider;
use App\Contracts\RecordStore;
use App\Infrastructure\FirestoreRecordStore;
use App\Infrastructure\SqlRecordStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RecordStore::class, fn () => config('crm.store') === 'firestore' ? new FirestoreRecordStore : new SqlRecordStore(DB::connection()));
    }

    public function boot(): void
    {
        Auth::provider('crm', fn ($app, array $config) => new CrmUserProvider($app->make(RecordStore::class)));
    }
}
