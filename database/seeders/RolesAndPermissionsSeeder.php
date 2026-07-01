<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Permissions ---
        // Profiling & Users
        Permission::create(['name' => 'view_users']);
        Permission::create(['name' => 'edit_users']);
        Permission::create(['name' => 'delete_users']);
        
        // Drivers & KYC
        Permission::create(['name' => 'view_drivers']);
        Permission::create(['name' => 'moderate_drivers']); // Approve/Reject KYC
        
        // Rides
        Permission::create(['name' => 'view_rides']);
        Permission::create(['name' => 'manage_rides']);
        
        // Finance & Tarification
        Permission::create(['name' => 'view_finance']);
        Permission::create(['name' => 'manage_pricing']);
        
        // Comms & Promo
        Permission::create(['name' => 'manage_promotions']);
        
        // Dev Tools & Monitoring
        Permission::create(['name' => 'view_dev_tools']);
        
        // Settings & RBAC
        Permission::create(['name' => 'manage_roles']);

        // --- Roles ---
        $supportRole = Role::create(['name' => 'support']);
        $supportRole->givePermissionTo([
            'view_users',
            'view_drivers',
            'view_rides',
        ]);

        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo([
            'view_users',
            'edit_users',
            'view_drivers',
            'moderate_drivers',
            'view_rides',
            'manage_rides',
            'view_finance',
            'manage_pricing',
            'manage_promotions'
        ]);
        
        $developerRole = Role::create(['name' => 'developer']);
        $developerRole->givePermissionTo([
            'view_dev_tools'
        ]);

        $superAdminRole = Role::create(['name' => 'super-admin']);
        // Super-admin gets all permissions via Gate::before in AuthServiceProvider or AppServiceProvider (optional)
        // Or we just give all permissions directly:
        $superAdminRole->givePermissionTo(Permission::all());
        
        // Sync roles to existing users based on their string 'role'
        $users = User::whereNotNull('role')->get();
        foreach ($users as $user) {
            if ($user->role === 'super-admin') {
                $user->assignRole('super-admin');
            } elseif ($user->role === 'admin') {
                $user->assignRole('admin');
            } elseif ($user->role === 'developer') {
                $user->assignRole('developer');
            } elseif ($user->role === 'support') {
                $user->assignRole('support');
            }
        }
    }
}
