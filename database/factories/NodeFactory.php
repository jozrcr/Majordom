<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    public function definition(): array
    {
        return [
            'execution_id' => Execution::factory(),
            'type' => $this->faker->word(),
            'status' => 'pending',
            'input' => null,
            'output' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
