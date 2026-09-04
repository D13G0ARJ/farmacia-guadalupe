<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Console\Commands\SendMonthlyReport;
use App\Models\Setting;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Parámetros globales (§4.5, UC-17). Cada campo lleva su explicación en la pantalla. */
class SettingsForm extends Form
{
    public int $sales_deviation_pct = 35;

    public int $rate_deviation_pct = 10;

    public int $operator_edit_window_days = 7;

    public int $goal_growth_pct = 5;

    public int $goal_on_track_pct = 100;

    public int $goal_at_risk_pct = 90;

    public string $goal_currency = 'USD';

    public string $gross_margin_pct = '';

    public string $app_name = '';

    public int $report_email_day = 0;

    public string $report_recipients = '';

    public bool $close_reminder_enabled = true;

    public function fillFromSettings(): void
    {
        $this->report_email_day = (int) Setting::get('report_email_day');
        $this->report_recipients = (string) Setting::get('report_recipients');
        $this->close_reminder_enabled = (bool) Setting::get('close_reminder_enabled');
        $this->sales_deviation_pct = (int) Setting::get('sales_deviation_pct');
        $this->rate_deviation_pct = (int) Setting::get('rate_deviation_pct');
        $this->operator_edit_window_days = (int) Setting::get('operator_edit_window_days');
        $this->goal_growth_pct = (int) Setting::get('goal_growth_pct');
        $this->goal_on_track_pct = (int) Setting::get('goal_on_track_pct');
        $this->goal_at_risk_pct = (int) Setting::get('goal_at_risk_pct');
        $this->goal_currency = (string) Setting::get('goal_currency');
        $margin = Setting::get('gross_margin_pct');
        $this->gross_margin_pct = $margin === null ? '' : (string) $margin;
        $this->app_name = (string) Setting::get('app_name');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sales_deviation_pct' => ['required', 'integer', 'between:5,100'],
            'rate_deviation_pct' => ['required', 'integer', 'between:1,100'],
            'operator_edit_window_days' => ['required', 'integer', 'between:0,60'],
            'goal_growth_pct' => ['required', 'integer', 'between:0,100'],
            'goal_on_track_pct' => ['required', 'integer', 'between:50,150', 'gt:goal_at_risk_pct'],
            'goal_at_risk_pct' => ['required', 'integer', 'between:1,149'],
            'goal_currency' => ['required', Rule::in(['USD', 'BS'])],
            'gross_margin_pct' => ['nullable', 'numeric', 'between:1,95'],
            'app_name' => ['required', 'string', 'min:3', 'max:80'],
            'report_email_day' => ['required', 'integer', 'between:0,28'],
            'report_recipients' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (preg_split('/[\s,;]+/', (string) $value) ?: [] as $email) {
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $fail("\"{$email}\" no es un correo válido.");
                    }
                }
            }],
            'close_reminder_enabled' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'goal_on_track_pct.gt' => 'El umbral de "en meta" debe ser mayor que el de "en riesgo".',
            'gross_margin_pct.between' => 'El margen bruto va de 1 a 95 por ciento.',
            '*.between' => 'Fuera del rango permitido.',
            '*.required' => 'Este valor es obligatorio.',
            '*.integer' => 'Escribe un número entero.',
        ];
    }

    /** @return array<string, mixed> */
    public function toValues(): array
    {
        return [
            'sales_deviation_pct' => $this->sales_deviation_pct,
            'rate_deviation_pct' => $this->rate_deviation_pct,
            'operator_edit_window_days' => $this->operator_edit_window_days,
            'goal_growth_pct' => $this->goal_growth_pct,
            'goal_on_track_pct' => $this->goal_on_track_pct,
            'goal_at_risk_pct' => $this->goal_at_risk_pct,
            'goal_currency' => $this->goal_currency,
            'gross_margin_pct' => $this->gross_margin_pct === '' ? null : (float) $this->gross_margin_pct,
            'app_name' => trim($this->app_name),
            'report_email_day' => $this->report_email_day,
            'report_recipients' => implode(', ', SendMonthlyReport::recipients($this->report_recipients)),
            'close_reminder_enabled' => $this->close_reminder_enabled,
        ];
    }
}
