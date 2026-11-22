<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Sellissimo\Core\Database\IndexName;
use Sellissimo\Core\Store;

return new class extends Migration
{
    public function getConnection()
    {
        return config('sellissimo.connection');
    }

    public function up(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        $schema->create('sellissimo_sz_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('site');
            $t->string('mode', 8);
            $t->text('credential');
            $t->timestamp('created_at');
        });
        $schema->create('sellissimo_sz_settings', function (Blueprint $t) {
            $t->string('site')->primary();
            $t->text('settings');
        });
        $schema->create('sellissimo_sz_placements', function (Blueprint $t) {
            $t->uuid('order_id')->primary();
            $t->string('site');
            $t->longText('payload');
            $t->timestamp('created_at');
        });
        $index = fn (string $name) => IndexName::forStore(app(Store::class)->db()->getTablePrefix(), $name);
        $schema->create('sellissimo_sz_documents', function (Blueprint $t) use ($index) {
            $t->uuid('id')->primary();
            $t->uuid('order_id')->index();
            $t->uuid('account_id');
            $t->string('site');
            $t->string('mode', 8);
            $t->string('kind', 16);
            $t->string('business_key', 128);
            $t->unique(['order_id', 'account_id', 'business_key'], $index('sz_document_operation'));
            $t->string('status', 16);
            $t->string('number', 128)->nullable();
            $t->unique(['account_id', 'number'], $index('sz_document_number'));
            $t->longText('payload');
            $t->text('reason')->nullable();
            $t->longText('pdf')->nullable();
            $t->boolean('email_requested')->default(false);
            $t->string('email_response')->nullable();
            $t->string('actor')->nullable();
            $t->timestamp('last_lookup_at')->nullable();
            $t->timestamps();
        });
        $schema->create('sellissimo_sz_attempts', function (Blueprint $t) {
            $t->id();
            $t->uuid('document_id')->index();
            $t->longText('payload');
            $t->text('reason');
            $t->string('actor')->nullable();
            $t->timestamp('attempted_at');
        });
        $schema->create('sellissimo_szamlazz_reviews', function (Blueprint $t) use ($index) {
            $t->uuid('id')->primary();
            $t->string('site');
            $t->uuid('order_id');
            $t->string('source', 100);
            $t->text('reason');
            $t->timestamp('last_attempt_at');
            $t->timestamp('resolved_at')->nullable();
            $t->unique(['order_id', 'source'], $index('sz_review_source'));
            $t->index(['site', 'resolved_at'], $index('sz_review_queue'));
        });
    }

    public function down(): void
    {
        $schema = app(Store::class)->db()->getSchemaBuilder();
        foreach (['szamlazz_reviews', 'sz_attempts', 'sz_documents', 'sz_placements', 'sz_settings', 'sz_accounts'] as $name) {
            $schema->dropIfExists('sellissimo_'.$name);
        }
    }
};
