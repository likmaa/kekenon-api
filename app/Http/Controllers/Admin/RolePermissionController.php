<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    /**
     * Get all roles and their permissions.
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();
        return response()->json($roles);
    }

    /**
     * Get all available permissions.
     */
    public function getPermissions()
    {
        $permissions = Permission::all();
        return response()->json($permissions);
    }

    /**
     * Assign permissions to a role.
     */
    public function syncRolePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name'
        ]);

        $role->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permissions synchronisées avec succès.',
            'role' => $role->load('permissions')
        ]);
    }

    /**
     * Get staff users (admins, super-admins, support, dev, etc.)
     * excluding standard passengers and drivers to manage their roles.
     */
    public function getStaffUsers()
    {
        $users = User::whereIn('role', ['admin', 'super-admin', 'developer', 'support'])
            ->with('roles')
            ->get();
            
        return response()->json($users);
    }

    /**
     * Assign a role to a user.
     */
    public function assignRoleToUser(Request $request, User $user)
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name'
        ]);

        $user->syncRoles([$request->role]);
        $user->role = $request->role; // Keep string role in sync just in case
        $user->save();

        return response()->json([
            'message' => 'Rôle assigné avec succès.',
            'user' => $user->load('roles')
        ]);
    }
}
