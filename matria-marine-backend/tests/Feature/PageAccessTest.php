<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An admin reaches only the screens they were given, and that is enforced on
 * the server — not merely by leaving the link out of the menu.
 */
class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $pages): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->assignRole('admin');
        $user->syncPermissions(array_map(fn ($k) => PortalPages::permission($k), $pages));
        Sanctum::actingAs($user);

        return $user;
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_payroll_is_closed_to_an_admin_without_it(): void
    {
        $this->admin(['enquiries', 'invoices']);

        $this->getJson('/api/payroll/runs')
            ->assertForbidden()
            ->assertJsonPath('page', 'payroll');

        $this->getJson('/api/payroll/employees')->assertForbidden();
    }

    public function test_payroll_opens_once_it_is_given(): void
    {
        $this->admin(['payroll']);

        $this->getJson('/api/payroll/runs')->assertOk();
    }

    public function test_super_admin_sees_everything_without_any_permissions(): void
    {
        $this->superAdmin();

        $this->getJson('/api/payroll/runs')->assertOk();
        $this->getJson('/api/portal/accounting/sales-invoices')->assertOk();
        $this->getJson('/api/portal/invoices')->assertOk();
    }

    public function test_each_page_guards_its_own_data(): void
    {
        $this->admin(['invoices']);

        $this->getJson('/api/portal/invoices')->assertOk();
        $this->getJson('/api/portal/purchase-orders')->assertForbidden();
        $this->getJson('/api/portal/offers')->assertForbidden();
        $this->getJson('/api/portal/accounting/sales-invoices')->assertForbidden();
        $this->getJson('/api/portal/reports/accounting')->assertForbidden();
    }

    public function test_shared_lookups_stay_open_so_other_screens_keep_working(): void
    {
        // Only Invoices — yet the invoice screen still needs the account
        // picker and the customer picker.
        $this->admin(['invoices']);

        $this->getJson('/api/portal/accounting/chart')->assertOk();
        $this->getJson('/api/portal/customers')->assertOk();
        $this->getJson('/api/portal/vendors')->assertOk();
    }

    public function test_reading_customers_is_open_but_changing_them_is_not(): void
    {
        $this->admin(['invoices']);

        $this->postJson('/api/portal/customers', ['name' => 'New Co'])->assertForbidden();
    }

    public function test_statements_are_their_own_page_despite_living_under_reports(): void
    {
        $this->admin(['statements']);

        $this->getJson('/api/portal/reports/statements?type=customer')->assertOk();
        $this->getJson('/api/portal/reports/accounting')->assertForbidden();
    }

    public function test_the_credit_memo_save_route_belongs_to_credit_memos_not_invoices(): void
    {
        $this->assertSame('credit_memos', PortalPages::pageFor('api/portal/invoices/12/credit-memo', 'POST'));
        $this->assertSame('invoices', PortalPages::pageFor('api/portal/invoices/12', 'GET'));
    }

    public function test_the_logged_in_user_is_told_which_pages_to_show(): void
    {
        $this->admin(['enquiries', 'payroll']);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.pages', ['enquiries', 'payroll']);
    }

    public function test_a_new_admin_gets_exactly_the_ticked_pages(): void
    {
        $this->superAdmin();
        Role::findOrCreate('admin', 'web');

        $this->postJson('/api/portal/users', [
            'name' => 'New Staff',
            'email' => 'new@example.com',
            'username' => 'newstaff',
            'password' => 'password123',
            'role' => 'admin',
            'pages' => ['enquiries', 'offers'],
        ])->assertCreated();

        $created = User::where('username', 'newstaff')->firstOrFail();
        $this->assertSame(['enquiries', 'offers'], PortalPages::visibleTo($created));
        $this->assertFalse(PortalPages::canSee($created, 'payroll'));
    }

    public function test_a_new_admin_with_nothing_ticked_gets_nothing(): void
    {
        $this->superAdmin();
        Role::findOrCreate('admin', 'web');

        $this->postJson('/api/portal/users', [
            'name' => 'Nobody',
            'email' => 'nobody@example.com',
            'username' => 'nobody',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertSame([], PortalPages::visibleTo(User::where('username', 'nobody')->firstOrFail()));
    }

    public function test_unticking_a_page_takes_it_away(): void
    {
        $super = $this->superAdmin();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');
        $admin->syncPermissions([PortalPages::permission('payroll'), PortalPages::permission('invoices')]);

        Sanctum::actingAs($super);
        $this->patchJson("/api/portal/users/{$admin->id}", ['pages' => ['invoices']])->assertOk();

        $this->assertFalse(PortalPages::canSee($admin->fresh(), 'payroll'));
        $this->assertTrue(PortalPages::canSee($admin->fresh(), 'invoices'));
    }
}
