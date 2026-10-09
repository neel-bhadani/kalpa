<?php

use App\Actions\RegisterUser;
use App\Enums\WorkspacePlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('builds the CHECK from the enum cases', function () {
    $definition = DB::selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'workspaces_plan_check'"
    )->def;

    foreach (WorkspacePlan::cases() as $plan) {
        expect($definition)->toContain("'{$plan->value}'");
    }
});

it('rejects a value outside the enum', function () {
    $user = app(RegisterUser::class)->handle('Ada', 'ada@example.com', 'secret-password');
    $workspace = $user->workspaces()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('workspaces')->where('id', $workspace->id)->update(['plan' => 'gold'])))
        ->toThrow(QueryException::class, 'workspaces_plan_check');

    expect(fn () => DB::transaction(fn () => DB::table('workspace_members')->update(['role' => 'admin'])))
        ->toThrow(QueryException::class, 'workspace_members_role_check');
});
