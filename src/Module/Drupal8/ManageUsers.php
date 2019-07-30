<?php

namespace Codeception\Module\Drupal8;

use Codeception\Module;
use Codeception\Module\DrupalTestUser;
use Codeception\Lib\Console\Message;
use Codeception\Lib\Console\Output;
use Codeception\Util\Debug;
use Drupal\user\Entity\User;

/**
 * Class ManageUsers
 * @package Codeception\Module\Drupal8
 */
class ManageUsers extends \Codeception\Module
{
  /**
   * Module yaml configuration.
   *
   * @var array
   */
  protected $config = array();

  /**
   * Codeception console output.
   *
   * @var Output
   */
  protected $output;

  /**
   * Required module config fields.
   *
   * @var array
   */
  protected $requiredFields = array('users');

  /**
   * Loaded drupal test users.
   *
   * @var DrupalTestUser[]
   */
  protected $drupalTestUsers = array();

  /**
   * Map with functions to prepare given field value.
   *
   * @var array
   */
  protected $prepareFieldValueFns = array(
    'date' => 'strtotime',
  );

  /**
   * Initialize the module. Load test users.
   */
  public function _initialize() {
    $this->output = new Output(array());
    $this->loadUsersFromConfig();
  }

  /**
   * Load test users from suite yaml config.
   */
  public function loadUsersFromConfig() {
    if (isset($this->config['users']) && is_array($this->config['users'])) {
      $defaultPass = isset($this->config['defaultPass']) ? $this->config['defaultPass'] : '';

      foreach ($this->config['users'] as $item) {
        $user = new DrupalTestUser(
          $item['name'],
          empty($item['pass']) ? $defaultPass : $item['pass'],
          $item['roles'],
          $item['email'],
          $item['custom_fields']
        );
        $this->drupalTestUsers[$item['name']] = $user;
      }
    }
    else {
      Debug::debug('"users" property not found in yaml configuration or property is empty.');
    }
  }

  /**
   * Create all test users at the start of test suite.
   *
   * @param array $settings
   */
  public function _beforeSuite($settings = array()) {
    if (isset($this->config['create']) && $this->config['create'] === true) {
      $this->createAllUsers();
    }
  }

  /**
   * Delete all test users at the end of test suite.
   *
   * @codeCoverageIgnore
   */
  public function _afterSuite() {
    if (isset($this->config['delete']) && $this->config['delete'] === true) {
      $this->deleteAllUsers();
    }
  }

  /**
   * Create all test users.
   */
  private function createAllUsers() {
    foreach ($this->drupalTestUsers as $testUser) {
      $this->createUser($testUser);
    }
  }

  /**
   * Prepare custom field value.
   *
   * @param $value
   * @param $type
   * @return mixed
   */
  private function prepareFieldValue($value, $type) {
    if (isset($this->prepareFieldValueFns[$type])) {
      $prepareFieldValueFn = $this->prepareFieldValueFns[$type];
      if (function_exists($prepareFieldValueFn)) {
        $value = $prepareFieldValueFn($value);
      }
    }
    return $value;
  }

  /**
   * Create test user.
   *
   * @param $user DrupalTestUser
   */
  private function createUser($user) {
    $drupal_user = user_load_by_name($user->name);
    if (!empty($drupal_user)) {
      $this->drupalTestUsers[$user->name]->uid = $drupal_user->id();
      $this->message("User '{$user->name}' already exists, skipping.")->writeln();
    }
    else {
      try {
        // Create the user.
        $this->message("Creating test user '{$user->name}'.")->writeln();

        $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
        $user_account = User::create();

        $user_account->setPassword($user->pass);
        $user_account->enforceIsNew();
        $user_account->setEmail($user->email);
        $user_account->setUsername($user->name);

        $user_account->set('init', $user->email);
        $user_account->set('langcode', $language);
        $user_account->set('preferred_langcode', $language);
        $user_account->set('preferred_admin_langcode', $language);

        // Add user roles.
        foreach ($user->roles as $rid) {
          $user_account->addRole($rid);
        }

        // Add profile fields values.
        if (count($user->custom_fields) > 0) {
          foreach ($user->custom_fields as $custom_field_name => $custom_field_value) {
            if (isset($custom_field_value['type'])) {
              $field_value = $this->prepareFieldValue($custom_field_value['value'], $custom_field_value['type']);
              $user_account->set($custom_field_name, $field_value);
            }
            else {
              $user_account->set($custom_field_name, $custom_field_value);
            }
          }
        }

        $user_account->activate();
        $saved = $user_account->save();
        if ($saved) {
          $this->drupalTestUsers[$user->name]->uid = $user_account->id();
          $this->message("User '{$user->name}' (uid: {$user_account->id()}) created.")
            ->writeln();
        }
        else {
          $this->message("User '{$user->name}' not created.")
            ->writeln();
        }
      }
      catch (\Exception $e) {
        $this->message($e->getMessage())
          ->writeln();
      }
    }
  }

  /**
   * Delete all test users.
   */
  private function deleteAllUsers() {
    foreach ($this->drupalTestUsers as $testUser) {
      $this->deleteUser($testUser);
    }
  }

  /**
   * Delete test user.
   *
   * @param $user DrupalTestUser
   */
  private function deleteUser($user) {
    /** @var $user_loaded User */
    $user_loaded = user_load_by_name($user->name);
    if ($user_loaded != FALSE) {
      user_delete($user_loaded->id());
      $this->message("User '{$user->name}' (uid: {$user_loaded->id()}) deleted.")
        ->writeln();
    }
  }

  /**
   * Print message in console.
   *
   * @param string $text
   * @return \Codeception\Lib\Console\Message
   */
  protected function message($text = '') {
    return new Message($text, $this->output);
  }

  /**
   * Check if test user with given username already exists.
   *
   * @param $username
   * @return bool
   */
  public function userExists($username) {
    return user_load_by_name($username) == FALSE ? FALSE : TRUE;
  }

  /**
   * Get all test users.
   *
   * @return DrupalTestUser[]
   */
  public function getAllTestUsers() {
    return $this->drupalTestUsers;
  }

  /**
   * Get users by role (if user have at least one of given role).
   *
   * @param array $roles
   * @return DrupalTestUser[]
   */
  public function getTestUsersByRoles($roles = array(), $return_one_user = FALSE) {
    /* @var $users DrupalTestUser[] */
    $users = array();
    foreach ($this->drupalTestUsers as $user) {
      $matched_roles = array_intersect($roles, $user->roles);
      if (count($matched_roles) > 0) {
        $users[] = $user;
      }
    }
    if ($return_one_user && count($users) > 0) {
      return end($users);
    }
    return $users;
  }

  /**
   * Get user by name.
   *
   * @param $username
   * @return DrupalTestUser
   */
  public function getTestUserByName($username) {
    /* @var $user DrupalTestUser */
    $user = $this->drupalTestUsers[$username];
    return $user;
  }
}
