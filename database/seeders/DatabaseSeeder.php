<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Billing\CreditLedgerService;
use App\Support\Money;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $ledger = app(CreditLedgerService::class);
        $adminEmail = (string) env('ADMIN_EMAIL', 'admin@pixelforge-studio.test');
        $adminPassword = (string) env('ADMIN_PASSWORD', 'password');
        $admin = User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Operator Admin',
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
            ],
        );
        if (! $admin->is_admin) {
            $admin->update(['is_admin' => true]);
        }
        if ($admin->creditLedgerEntries()->doesntExist()) {
            $ledger->purchaseCredits(
                $admin,
                Money::fromDecimalString('25.00')->nanos,
                'Initial operator demo balance',
            );
        }
        $demo = User::query()->firstOrCreate(
            ['email' => 'demo@pixelforge-studio.test'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'is_admin' => false,
            ],
        );
        if ($demo->creditLedgerEntries()->doesntExist()) {
            $ledger->purchaseCredits(
                $demo,
                Money::fromDecimalString('5.00')->nanos,
                'Initial demo user balance',
            );
        }
    }
}
