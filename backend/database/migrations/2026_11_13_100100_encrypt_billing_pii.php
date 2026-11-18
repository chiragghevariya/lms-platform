<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing tax ids and addresses become encrypted at rest (APP_KEY). Columns turn into TEXT
 * (ciphertext isn't JSON and is longer than 32 chars) and existing values are re-written.
 */
return new class extends Migration
{
    /** @var array<string, array<string, bool>> table => column => is_json */
    private const COLUMNS = [
        'users' => ['billing_address' => true, 'tax_id' => false],
        'payments' => ['billing_address' => true, 'billing_tax_id' => false],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                foreach (array_keys($cols) as $c) {
                    $t->text($c)->nullable()->change();
                }
            });
            DB::table($table)->select(['id', ...array_keys($cols)])->orderBy('id')->chunkById(500, function ($rows) use ($table, $cols) {
                foreach ($rows as $row) {
                    $update = [];
                    foreach ($cols as $c => $json) {
                        $v = $row->{$c};
                        if ($v !== null && $v !== '') {
                            // JSON columns: the cast stores encrypt(json); plain: encrypt(string).
                            $update[$c] = Crypt::encryptString($json ? (string) $v : (string) $v);
                        }
                    }
                    if ($update) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            DB::table($table)->select(['id', ...array_keys($cols)])->orderBy('id')->chunkById(500, function ($rows) use ($table, $cols) {
                foreach ($rows as $row) {
                    $update = [];
                    foreach (array_keys($cols) as $c) {
                        if ($row->{$c}) {
                            $update[$c] = Crypt::decryptString($row->{$c});
                        }
                    }
                    if ($update) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }
    }
};
