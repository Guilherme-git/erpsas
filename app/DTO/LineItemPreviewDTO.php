<?php

namespace App\DTO;

readonly class LineItemPreviewDTO extends LineItemDTO
{
    public static function fakeItems(): array
    {
        return [
            new self(
                name: 'Professional Services',
                description: 'Consulting and strategic planning',
                quantity: 2,
                unitPrice: self::formatToMoney(15000, null), // $150.00
                subtotal: self::formatToMoney(30000, null),  // $300.00
            ),
            new self(
                name: 'Software License',
                description: 'Annual subscription and support',
                quantity: 3,
                unitPrice: self::formatToMoney(20000, null), // $200.00
                subtotal: self::formatToMoney(60000, null),  // $600.00
            ),
            new self(
                name: 'Training Session',
                description: 'Team onboarding and documentation',
                quantity: 1,
                unitPrice: self::formatToMoney(10000, null), // $100.00
                subtotal: self::formatToMoney(10000, null),  // $100.00
            ),
        ];
    }
}
