<?php

namespace YesWiki\Core\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Core\Entity\User;
use YesWiki\Core\Exception\BadFormatPasswordException;
use YesWiki\Core\Service\PasswordHasherFactory;
use YesWiki\Core\Service\UserManager;
use YesWiki\Core\YesWikiController;
use YesWiki\Security\Controller\SecurityController;

// this trait should be into includes/traits/LimitationsTrait folder
// with namespace namespace YesWiki\Core\Trait; but it is not working for old php version previous php 8

trait LimitationsTrait
{
    /**
     * init and store limitations in limitations array
     * @param string $parameterName
     * @param string $limitationKey
     * @param mixed $type
     * @param mixed $default
     * @param string $errorMessageKey
     */
    private function initLimitationHelper(string $parameterName, string $limitationKey, $type, $default, string $errorMessageKey)
    {
        $this->limitations[$limitationKey] = $default;
        if ($this->params->has($parameterName)) {
            $parameter = $this->params->get($parameterName);
            if (!filter_var($parameter, FILTER_VALIDATE_INT)) {
                trigger_error(_t($errorMessageKey));
            } else {
                $this->limitations[$limitationKey] = $parameter;
            }
        }
    }
}

class AuthController extends YesWikiController
{
    use LimitationsTrait;

    public const DEFAULT_PASSWORD_MINIMUM_LENGTH = 5;

    private $limitations;
    protected $params;
    protected $passwordHasherFactory;
    protected $securityController;
    protected $userManager;

    public function __construct(
        ParameterBagInterface $params,
        PasswordHasherFactory $passwordHasherFactory,
        SecurityController $securityController,
        UserManager $userManager
    ) {
        $this->params = $params;
        $this->passwordHasherFactory = $passwordHasherFactory;
        $this->userManager = $userManager;
        $this->securityController = $securityController;
        $this->initLimitations();
    }

    /** Initializes object limitation properties using values from the config file
     *
     * @return void
     */
    private function initLimitations()
    {
        $this->limitations = [];
        $this->initLimitationHelper(
            'user_password_min_length',
            'passwordMinimumLength',
            FILTER_VALIDATE_INT,
            self::DEFAULT_PASSWORD_MINIMUM_LENGTH,
            'USER_PASSWORD_MIN_LENGTH_NOT_INT'
        );
    }

    /** checks if the given string is the user's password
     *
     * @param string $plainTextPassword
     * @param User $user
     * @return boolean True if OK or false if any problems
     */
    public function checkPassword(string $plainTextPassword, User $user)
    {
        $passwordHasher = $this->passwordHasherFactory->getPasswordHasher($user);
        $hashedPassword = $user->getPassword();
        if (!$passwordHasher->verify($hashedPassword, $plainTextPassword)) {
            return false;
        }
        if ($passwordHasher->needsRehash($hashedPassword) && !$this->securityController->isWikiHibernated()) {
            $newHashedPassword = $passwordHasher->hash($plainTextPassword);
            $this->userManager->upgradePassword($user, $newHashedPassword);
        }
        return true;
    }

    /**
     * force a new password when renewing password
     * @param User $user
     * @param string $plainTextPassword
     * @throws BadFormatPasswordException
     */
    public function setPassword(User $user, string $plainTextPassword)
    {
        $this->checkPasswordValidateRequirements($plainTextPassword);
        $passwordHasher = $this->passwordHasherFactory->getPasswordHasher($user);
        $newHashedPassword = $passwordHasher->hash($plainTextPassword);
        $this->userManager->upgradePassword($user, $newHashedPassword);
    }

    /**
     * check if password respets the requirements
     * @param string $password
     * @return bool
     * @throws BadFormatPasswordException
     */
    public function checkPasswordValidateRequirements(string $password):bool
    {
        if (strlen($password) < $this->limitations['passwordMinimumLength']) {
            throw new BadFormatPasswordException(_t('USER_PASSWORD_TOO_SHORT').'. '._t('USER_PASSWORD_MINIMUM_NUMBER_OF_CHARACTERS_IS').' ' .$this->limitations['passwordMinimumLength'].'.');
        }
        return true;
    }

    /**
     * connect a user from SESSION or COOKIES
     */
    public function connectUser()
    {
        $userFromSession = $this->getLoggedUser();
        if (!empty($userFromSession['name'])) {
            // check if user ever existing
            $user = $this->userManager->getOneByName($userFromSession['name']);
            $remember = $userFromSession['remember'] ?? 0;
            if (!empty($user)) {
                if (empty($userFromSession['lastConnection'])) {
                    if (!$this->wiki->UserIsAdmin()) {
                        // do not disconnect admin during update
                        $user = null;
                    }
                } elseif ((intval($userFromSession['lastConnection']) + ($remember ? 90 * 24 * 60 * 60 : 60 * 60)) < time()) {
                    // like Session.class->setPersistentCookie()
                    // If $remember is set and different from 0, 90 days, 1 hour otherwise
                    $user = null;
                }
            }
        }
        if (empty($user) && !empty($_COOKIE['name']) && is_string($_COOKIE['name'])) {
            $user = $this->userManager->getOneByName($_COOKIE['name']);
            $remember = $_COOKIE['remember'] ?? 0;
            if (!empty($user) && (
                empty($_COOKIE['password']) ||
                    !is_string($_COOKIE['password']) ||
                    $_COOKIE['password'] != $user['password'] // this is the key point where comparisn is done with password
            )) {
                // not right connected user
                $user = null;
            }
        }
        if (empty($user)) {
            $this->logout();
        } else {
            $this->login($user, $remember);
            // login each time to set persistent cookies
        }
    }

    // methods imported from UserManager

    public function getLoggedUser()
    {
        return isset($_SESSION['user']) ? $_SESSION['user'] : '';
    }

    public function getLoggedUserName()
    {
        if ($user = $this->getLoggedUser()) {
            $name = $user["name"];
        } else {
            $name = $this->wiki->isCli() ? '' : $_SERVER["REMOTE_ADDR"];
        }
        return $name;
    }

    public function login($user, $remember = 0)
    {
        if (isset($_SESSION['user']) && isset($_SESSION['user']['remember']) && $_SESSION['user']['name'] == $user['name']) {
            $remember = filter_var($_SESSION['user']['remember'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        } else {
            $remember = filter_var($remember, FILTER_VALIDATE_BOOL) ? 1 : 0;
        }
        $_SESSION['user'] = array_merge(
            ($user instanceof User ? $user->getArrayCopy() : (
                is_array($user) ? $user: []
            )),
            [
                'remember' => $remember,
                'lastConnection' => time()
            ]
        );
        if (!$this->wiki->isCli()) {
            // prevent setting cookies in CLI (could be errors)
            $this->wiki->SetPersistentCookie('name', $user['name'], $remember);
            $this->wiki->SetPersistentCookie('password', $user['password'], $remember);
            $this->wiki->SetPersistentCookie('remember', $remember, $remember);
        }
    }

    public function logout()
    {
        $_SESSION['user'] = '';
        if (!$this->wiki->isCli()) {
            // prevent setting cookies in CLI (could be errors)
            $this->wiki->DeleteCookie('name');
            $this->wiki->DeleteCookie('password');
            $this->wiki->DeleteCookie('remember');
        }
    }
}