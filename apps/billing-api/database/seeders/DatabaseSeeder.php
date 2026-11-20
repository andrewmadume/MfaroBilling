<?php

namespace Database\Seeders;

use App\Models\Bundle;
use App\Models\Offer;
use App\Models\OfferPrice;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['MFAROPOS', 'MfaroPOS', 'Sales, stock and customers. Keep your business moving.', ['sales', 'inventory', 'customers', 'staff', 'reports', 'multi_branch', 'receipts']],
            ['MFAROINVOICE', 'MfaroInvoice', 'Professional invoices, quotations and effortless payment tracking.', ['invoices', 'quotations', 'customers', 'payment_tracking', 'reports', 'custom_templates', 'recurring_invoices']],
            ['MFAROHR', 'Mfaro HR & Payroll', 'Look after your people, from employee records to payroll.', ['employees', 'leave', 'attendance', 'payroll', 'payslips', 'hr_reports', 'roles']],
            ['MFAROEDU', 'MfaroEdu', 'A connected school community, from attendance to report cards.', ['students', 'teachers', 'attendance', 'assessments', 'results', 'school_fees', 'parent_portal']],
            ['MFAROBOOKING', 'MfaroBooking', 'Make time for your customers with smarter scheduling.', ['appointments', 'customers', 'staff_calendars', 'online_bookings', 'payment_tracking', 'reminders', 'reports']],
        ];
        $plans = [];
        foreach ($products as [$code,$name,$description,$features]) {
            $p = Product::firstOrCreate(['code' => $code], ['name' => $name, 'description' => $description, 'features' => array_fill_keys($features, true)]);
            foreach (['basic' => 199, 'professional' => 499, 'business' => 999, 'enterprise' => 1999] as $tier => $amount) {
                $f = array_fill_keys($features, true);
                if ($tier === 'basic') {
                    foreach (array_slice($features, 4) as $feature) {
                        $f[$feature] = false;
                    }
                }
                $plan = Plan::firstOrCreate(['product_id' => $p->id, 'code' => $tier], ['name' => ucfirst($tier), 'description' => $tier === 'basic' ? 'The essentials for getting started.' : 'More room to grow, with powerful tools for your team.', 'features' => $f, 'limits' => ['max_users' => match ($tier) {
                    'basic' => 3,'professional' => 10,'business' => 30,default => null
                }, 'max_branches' => match ($tier) {
                    'basic' => 1,'professional' => 3,'business' => 10,default => null
                }], 'trial_days' => 14, 'featured' => $tier === 'professional', 'display_order' => array_search($tier, ['basic', 'professional', 'business', 'enterprise'])]);
                $plans[$code][$tier] = $plan;
                $offer = Offer::firstOrCreate(['plan_id' => $plan->id], ['name' => $name.' '.$plan->name]);
                $this->prices($offer, $amount, $amount * 10);
            }
        }
        foreach ([
            ['STARTER', 'Mfaro Starter', 'The everyday essentials for service businesses.', ['MFAROINVOICE' => 'basic', 'MFAROBOOKING' => 'basic'], 299],
            ['BUSINESS', 'Mfaro Business', 'Connected operations for a growing business.', ['MFAROPOS' => 'professional', 'MFAROINVOICE' => 'professional', 'MFAROHR' => 'basic'], 999],
            ['EDUCATION', 'Mfaro Education', 'Bring school administration and invoicing together.', ['MFAROEDU' => 'professional', 'MFAROINVOICE' => 'professional'], 799],
            ['COMPLETE', 'Mfaro Complete', 'All five Mfaro products, connected under one agreement.', array_fill_keys(array_column($products, 0), 'professional'), 1799],
        ] as [$code,$name,$description,$items,$amount]) {
            $bundle = Bundle::firstOrCreate(['code' => $code], ['name' => $name, 'description' => $description, 'trial_days' => 14]);
            foreach ($items as $product => $tier) {
                DB::table('bundle_items')->updateOrInsert(['bundle_id' => $bundle->id, 'plan_id' => $plans[$product][$tier]->id], []);
            }
            $offer = Offer::firstOrCreate(['bundle_id' => $bundle->id], ['name' => $name]);
            $this->prices($offer, $amount, $amount * 10);
        }
    }

    private function prices(Offer $o, int $monthly, int $annual): void
    {
        foreach (['monthly' => $monthly, 'annual' => $annual] as $interval => $amount) {
            $p = OfferPrice::firstOrCreate(['offer_id' => $o->id, 'interval' => $interval, 'currency' => 'ZAR'], ['amount' => number_format($amount, 2, '.', '')]);
            if (! DB::table('price_versions')->where('offer_price_id', $p->id)->exists()) {
                DB::table('price_versions')->insert(['id' => Str::uuid(), 'offer_price_id' => $p->id, 'amount' => $p->amount, 'currency' => 'ZAR', 'effective_at' => now()]);
            }
        }
    }
}
