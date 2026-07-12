<?php

namespace Database\Seeders;

use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Workflow::updateOrCreate(
            ['name' => 'Implement Feature'],
            [
                'is_builtin' => true,
                'chain' => ImplementFeatureWorkflow::CHAIN,
                'description' => 'Build → test → review → human gate → commit suggestion',
            ]
        );
    }
}
