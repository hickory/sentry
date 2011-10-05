<?php

/**
 * Sentry Auth User Class
 *
 * @author  Daniel Petrie
 */

namespace Sentry;

class SentryUserException extends \Fuel_Exception {}

class Sentry_User
{
	// set class properties
	protected $user = array();
	protected $table = null;
	protected $login_id = null;

	/**
	 * Class Construtor
	 *
	 * @param int
	 */
	public function __construct($id = null)
	{
		// load and set config
		\Config::load('sentry', true);
		$this->table = strtolower(\Config::get('sentry.table.users'));
		$this->login_id = strtolower(\Config::get('sentry.login_id'));

		// if an ID was passed
		if ($id)
		{
			// make sure ID is valid
			if (!is_int($id) or $id <= 0)
			{
				throw new \SentryUserException('User ID must be a valid integer greater than 0.');
			}
			else
			{
				//query database for user
				$user = \DB::select()
					->from($this->table)
					->where('id', $id)
					->execute()
					->current();

				// if there was a result - update user
				if (count($user))
				{
					$this->user = $user;
				}
				// user ID doesn't exist
				else
				{
					throw new \SentryUserException('User ID does not exist.');
				}
			}
		}
	}

	/**
	 * Register a user - Alias of create()
	 *
	 * @param array
	 */
	public function register($user)
	{
		$this->create($user);
	}

	/**
	 * Create User
	 *
	 * @param array
	 */
	public function create($user)
	{
		// make sure $user param is an array
		if (!is_array($user))
		{
			throw new \SentryUserException('Create/Register paramater must be an array.');
		}

		// check for required fields
		if (empty($user[$this->login_id]) or empty($user['password']))
		{
			throw new \SentryUserException('login_id and password can not be empty.');
		}

		// check to see if login_id is already taken
		if ($this->user_exists($user[$this->login_id]))
		{
			throw new \SentryUserException(ucfirst($this->login_id).' already exists.');
		}

		// set new user values
		$new_user = array(
			$this->login_id => $user[$this->login_id],
			'password' => $this->generate_password($user['password']),
			'created_at' => time(),
		);

		// insert new user
		list($insert_id, $rows_affected) = \DB::insert($this->table)->set($new_user)->execute();

		return ($rows_affected > 0) ? $insert_id : false;

	}

	/**
	 * Update User
	 *
	 * @param array
	 */
	public function update($fields)
	{
		// make sure a user id is set
		if (empty($this->user['id']))
		{
			throw new \SentryUserException('No user is selected to update.');
		}

		// make sure fields is an array
		if (!is_array($fields))
		{
			throw new \SentryUserException('Update param must be an array');
		}

		// init update array
		$update = array();

		// if updating login_id, make sure it does not exist
		if (array_key_exists('login_id', $fields))
		{
			if ($this->user_exists($fields['login_id'])
				and $fields['login_id'] != $this->user[$this->login_id]) // gracious check...
			{
				throw new \SentryUserException(ucfirst($this->login_id).' already exists.');
			}
			if (empty($fields['login_id']))
			{
				throw new \SentryUserException(ucfirst($this->login_id).' must not be blank.');
			}
			$update[$this->login_id] = $fields['login_id'];
			unset($fields['login_id']);
		}

		// update password
		if (array_key_exists('password', $fields))
		{
			if (empty($fields['password']))
			{
				throw new \SentryUserException('Password must not be blank.');
			}
			$update['password'] = $this->generate_password($fields['password']);
			unset($fields['password']);
		}

		// update password reset hash
		if (array_key_exists('password_reset', $fields))
		{
			$update['password_reset_hash'] = $this->generate_password($fields['password_reset']);
			unset($fields['password_reset']);
		}

		// add update time
		$update['updated_at'] = time();

		// update database
		$result = \DB::update($this->table)
			->set($update)
			->where('id', $this->user['id'])
			->execute();

		if ($result)
		{
			// change user values in object
			$this->user = array_merge($this->user, $update);

			return true;
		}

		return false;
	}

	/**
	 * Delete User
	 */
	public function delete()
	{
		// make sure a user id is set
		if (empty($this->user['id']))
		{
			throw new \SentryUserException('No user is selected to delete.');
		}

		// delete user from database
		$result = \DB::delete($this->table)
			->where('id', $this->user['id'])
			->execute();

		// if user was deleted
		if ($result)
		{
			// update user to null
			$this->user = array();

			return true;
		}

		return false;
	}

	/**
	 * Get User Data
	 *
	 * @param string, array
	 */
	public function get($field = null)
	{
		// make sure a user id is set
		if (empty($this->user['id']))
		{
			throw new \SentryUserException('No user is selected to get.');
		}

		// if no fields were passed - return entire user
		if ($field === null)
		{
			return $this->user;
		}
		// if field is an array - return requested fields
		else if (is_array($field))
		{
			$values = array();

			// loop through requested fields
			foreach ($field as $key)
			{
				// check to see if field exists in user
				if (array_key_exists($key, $this->user))
				{
					$values[$key] = $this->user[$key];
				}
				else
				{
					throw new \SentryUserException('\''.$key.'\' does not exist in \'user\' '.
								'object.');
				}
			}

			return $values;
		}
		// if single field was passed - return its value
		else
		{
			// check to see if field exists in user
			if (array_key_exists($field, $this->user))
			{
				return $this->user[$field];
			}

			throw new \SentryUserException('\''.$field.'\' does not exist in \'user\' object.');
		}
	}

	/**
	 * Change Password
	 *
	 * @param string
	 * @param string
	 */
	public function change_password($password, $old_password)
	{
		// make sure old password matches the current password
		if (!$this->check_password($old_password))
		{
			throw new \SentryUserException('Old password is invalid');
		}
		return $this->update(array('password' => $password));
	}

	/** Acl methods if needed **/



	/** Helpers **/

	/**
	 * Check if user exists already
	 *
	 * @param string
	 */
	protected function user_exists($login_id)
	{
		// query db to check for login_id
		$result = \DB::select()
			->from($this->table)
			->where($this->login_id, $login_id)
			->limit(1)
			->execute();

		return count($result);
	}

	/**
	 * Check Password
	 *
	 * @param string
	 * @param string
	 */
	protected function check_password($password)
	{
		// grabs the salt from the current password
		$salt = substr($this->user['password'], 0, 16);

		// hash the inputted password
		$password = $salt.$this->hash_password($password, $salt);

		// check to see if passwords match
		return $password == $this->user['password'];
	}

	protected function generate_password($password)
	{
		$salt = \Str::random('alnum', 16);

		return $salt.$this->hash_password($password, $salt);
	}

	protected function hash_password($password, $salt)
	{
		$password = hash('sha256', $salt.$password);

		return $password;
	}

}
