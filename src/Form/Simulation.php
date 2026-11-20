<?php

declare(strict_types=1);

namespace App\Form;

use App\Demo\Scenarios;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something to make happen at the demo company, from the dashboard.
 */
final readonly class Simulation
{
    public function __construct(
        #[Assert\Choice(callback: [self::class, 'scenarios'])]
        public string $scenario,
        #[Assert\Choice(callback: [self::class, 'customers'])]
        public string $customer = 'ana',
    ) {
    }

    /**
     * @return list<string>
     */
    public static function scenarios(): array
    {
        return array_keys(Scenarios::ALL);
    }

    /**
     * @return list<string>
     */
    public static function customers(): array
    {
        return array_keys(Scenarios::CUSTOMERS);
    }
}
