<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Enum\Role;
use App\Security\AccountManagementPolicy;
use PHPUnit\Framework\TestCase;

/**
 * ADR-068 (replacing ADR-050's TechSupportVisibility): an admin manages plain users; a super admin also manages
 * admins and super admins; tech support manages everyone and is invisible to everyone else.
 */
final class AccountManagementPolicyTest extends TestCase
{
    private AccountManagementPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AccountManagementPolicy();
    }

    private function account(string ...$roles): User
    {
        $user = new User();
        $user->setEmail(uniqid('policy-', true) . '@example.com');
        $user->setName('Test');
        $user->setPassword('irrelevant');
        $user->setRoles($roles);

        return $user;
    }

    public function testTechSupportRoleSurvivesTheSetRolesAllowlist(): void
    {
        $tech = $this->account('ROLE_TECH_SUPPORT');

        self::assertContains('ROLE_TECH_SUPPORT', $tech->getRoles());
        self::assertSame(Role::TechSupport, $tech->getPrimaryRole());
    }

    public function testUnknownRolesAreDroppedOnWrite(): void
    {
        $user = $this->account('ROLE_GOD', 'ROLE_ADMIN');

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], array_values($user->getRoles()));
    }

    public function testSuperAdminCannotSeeOrManageTechSupport(): void
    {
        $super = $this->account('ROLE_SUPER_ADMIN');

        self::assertFalse($this->policy->canManage($super, $this->account('ROLE_TECH_SUPPORT')));
        self::assertSame(['ROLE_TECH_SUPPORT'], $this->policy->hiddenRoles($super));
        self::assertFalse($this->policy->canAssign($super, 'ROLE_TECH_SUPPORT'));
    }

    public function testTechSupportManagesEveryoneIncludingEachOther(): void
    {
        $tech = $this->account('ROLE_TECH_SUPPORT');

        foreach (Role::values() as $role) {
            self::assertTrue($this->policy->canManage($tech, $this->account($role)), $role);
            self::assertTrue($this->policy->canAssign($tech, $role), $role);
        }
        self::assertSame([], $this->policy->hiddenRoles($tech));
    }

    public function testSuperAdminManagesUsersAdminsAndSuperAdmins(): void
    {
        $super = $this->account('ROLE_SUPER_ADMIN');

        self::assertTrue($this->policy->canManage($super, $this->account('ROLE_USER')));
        self::assertTrue($this->policy->canManage($super, $this->account('ROLE_ADMIN')));
        self::assertTrue($this->policy->canManage($super, $this->account('ROLE_SUPER_ADMIN')));
        self::assertSame([Role::User, Role::Admin, Role::SuperAdmin], $this->policy->assignableRoles($super));
    }

    public function testAdminManagesOnlyPlainUsers(): void
    {
        $admin = $this->account('ROLE_ADMIN');

        self::assertTrue($this->policy->canManage($admin, $this->account('ROLE_USER')));
        self::assertFalse($this->policy->canManage($admin, $this->account('ROLE_ADMIN')));
        self::assertFalse($this->policy->canManage($admin, $this->account('ROLE_SUPER_ADMIN')));
        self::assertFalse($this->policy->canAssign($admin, 'ROLE_ADMIN'));
        self::assertTrue($this->policy->canAssign($admin, 'ROLE_USER'));
        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT'], $this->policy->hiddenRoles($admin));
    }

    public function testPlainUserManagesNobody(): void
    {
        $user = $this->account('ROLE_USER');

        self::assertFalse($this->policy->canManage($user, $this->account('ROLE_USER')));
        self::assertSame([], $this->policy->assignableRoles($user));
        self::assertFalse($this->policy->canAssign($user, 'ROLE_NOT_A_ROLE'));
    }
}
