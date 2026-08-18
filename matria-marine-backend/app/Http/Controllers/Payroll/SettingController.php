<?php

namespace App\Http\Controllers\Payroll;

use App\Models\Payroll\Setting;
use App\Support\Payroll\CpfRates;
use Illuminate\Http\Request;

/**
 * The payslip letterhead, plus the statutory figures on show so the office can
 * see what the calculations are using without opening the code.
 */
class SettingController extends PayrollController
{
    public function show()
    {
        return $this->ok([
            'settings' => Setting::current(),
            'reference' => [
                'year' => CpfRates::YEAR,
                'ow_ceiling' => CpfRates::OW_CEILING,
                'annual_ceiling' => CpfRates::ANNUAL_CEILING,
                'sdl_rate' => CpfRates::SDL_RATE,
                'sdl_min' => CpfRates::SDL_MIN,
                'sdl_max' => CpfRates::SDL_MAX,
                'cpf_schemes' => CpfRates::schemeNames(),
                'shg_funds' => CpfRates::shgNames(),
                'age_bands' => CpfRates::AGE_BANDS,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $settings = Setting::current();

        $settings->update($request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'uen' => ['nullable', 'string', 'max:64'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'max:8'],
            'default_payment_method' => ['nullable', 'string', 'max:100'],
            'payslip_footer' => ['nullable', 'string', 'max:300'],
        ]));

        return $this->ok(['settings' => $settings->fresh()], 'Saved.');
    }
}
