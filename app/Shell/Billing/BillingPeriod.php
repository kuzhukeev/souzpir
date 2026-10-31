<?php

namespace App\Shell\Billing;

final class BillingPeriod
{
    public static function rules(): array
    {
        $date = ['nullable', 'date_format:Y-m-d', 'regex:/^(?!0000-)\d{4}-\d{2}-\d{2}$/D', 'before_or_equal:9999-12-30'];

        return ['from' => ['required_with:to', ...$date], 'to' => ['required_with:from', ...$date, 'after_or_equal:from']];
    }

    public static function messages(): array
    {
        return ['required_with' => __('Shell/Billing/messages.period.required'), 'date_format' => __('Shell/Billing/messages.period.date'),
            'regex' => __('Shell/Billing/messages.period.date'), 'before_or_equal' => __('Shell/Billing/messages.period.date'),
            'after_or_equal' => __('Shell/Billing/messages.period.order')];
    }
}
