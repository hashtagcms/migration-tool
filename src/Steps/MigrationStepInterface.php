<?php

namespace HashtagCms\MigrationTool\Steps;

interface MigrationStepInterface
{
    /**
     * Execute the migration step
     * @param int $siteId The source site ID
     * @param array $config Migration configuration
     * @return array Result summary
     */
    public function execute(int $siteId, array $config): array;
    
    /**
     * Get the name of the step
     * @return string
     */
    public function getName(): string;
}
