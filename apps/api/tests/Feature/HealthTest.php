<?php

it('reports app, db and redis as ok', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'app' => 'ok',
            'db' => 'ok',
            'redis' => 'ok',
        ]);
});
