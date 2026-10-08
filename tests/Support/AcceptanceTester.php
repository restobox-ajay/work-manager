<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Inherited Methods
 * @method void wantTo($text)
 * @method void wantToTest($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
 */
class AcceptanceTester extends \Codeception\Actor
{
    use _generated\AcceptanceTesterActions;

    // Enables $I->haveFriend(...) for multi-session scenarios (a second, independently
    // cookie-jarred browser). The base \Codeception\Actor does not include this trait;
    // PhpBrowser implements MultiSession, so friends get their own session.
    use \Codeception\Lib\Actor\Shared\Friend;

    /**
     * Submit the user login form with the given credentials.
     */
    public function loginAsUser(string $email, string $password = 'password123'): void
    {
        $this->amOnPage('/login');
        $this->submitForm('form', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Sign an admin in. Since ADR-068 admins are users with an admin role and use the one /login form.
     */
    public function loginAsAdmin(string $email, string $password = 'password123'): void
    {
        $this->loginAsUser($email, $password);
    }
}
