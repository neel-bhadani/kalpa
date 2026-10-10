<?php

use App\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Gate;

it('opens the Horizon dashboard only for owners, and only in local', function () {
    $ada = owner();
    $editor = memberOf(workspaceOf($ada), WorkspaceRole::Editor);

    // Tests run as "testing": closed for everyone.
    expect(Gate::forUser($ada)->check('viewHorizon'))->toBeFalse();

    app()->detectEnvironment(fn () => 'local');

    expect(Gate::forUser($ada)->check('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($editor)->check('viewHorizon'))->toBeFalse()
        ->and(Gate::check('viewHorizon'))->toBeFalse(); // nobody signed in
});
