<?php

return [
    'prefix' => 'cms-migration',
    'middleware' => ['web', 'auth'], // Adjust based on hashtagcms requirements
    'auto_queue_work_once' => true,
];
