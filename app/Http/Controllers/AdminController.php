<?php

namespace App\Http\Controllers;

use App\Services\AdminOrganizationService;
use App\Support\AdminContext;
use Illuminate\View\View;

/**
 * 后台管理控制台页面（/admin）
 *
 * 页面只出骨架与启动数据，列表/编辑都走 /api/admin/* 接口（与数据概览页同一套路）。
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly AdminContext $admin,
        private readonly AdminOrganizationService $organizations
    ) {}

    public function index(): View
    {
        $user = $this->admin->user();

        return view('admin', [
            'boot' => [
                'user' => [
                    'id' => $user?->getKey(),
                    'username' => $user?->username,
                    'name' => $user?->name,
                    'role' => $user?->currentRole(),
                    'role_label' => $user?->role_label,
                ],
                'is_super_admin' => $this->admin->isSuperAdmin(),
                'organizations' => $this->organizations->list(),
                'current_organization' => $this->admin->currentOrganization(),
                'manageable_codes' => $this->admin->manageableCodes(),
            ],
        ]);
    }
}
