<?php

use PragmaRX\Google2FA\Google2FA;
use Predis\Client;

/**
 * Class Synology
 */
class Synology
{
    /**
     * @var string
     */
    private $auth_url = 'https://synology.url:5001/webapi/auth.cgi';
    /**
     * @var string
     */
    private $url = 'https://synology.url:5001/webapi/entry.cgi';
    /**
     * @var string
     */
    private $account = 'admin';
    /**
     * @var string
     */
    private $password = '123456789';
    /**
     * @var null
     */
    private $sid = NULL;

    /**
     * Synology constructor.
     * @throws \PragmaRX\Google2FA\Exceptions\InvalidCharactersException
     * @throws \PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException
     */
    public function __construct()
    {
        $Redis = new Client();
        //check to see if session ID is in cache.  If found set and use it.
        if ($Redis->exists('sid'))
        {
            $this->sid = $Redis->get('sid');
        }
        else
        {
            //sid not in cache so authenticate with DSM
            $Google2fa = new Google2FA();

            $params = array(
                    'account'  => $this->account,
                    'passwd'   => $this->password,
                    'session'  => 'default',
                    'format'   => 'sid',
                    'api'      => 'SYNO.API.Auth',
                    'version'  => '3',
                    'method'   => 'login',
                    'otp_code' => $Google2fa->getCurrentOtp('Synology 2FA Key')
            );

            $response = $this->send_request($this->auth_url, $params);

            if (isset($response['data']) && $response['data']['sid'])
            {
                $this->sid = $response['data']['sid'];
                //set sid in cache
                $Redis->set('sid', $this->sid, 'ex', 600);
            }
        }
    }

    /**
     * @param $folder_name
     * @return mixed
     */
    public function get_acl($folder_name)
    {
        $params = array(
                'type'      => 'all',
                'file_path' => '/volume1/Shared/' . $folder_name,
                'api'       => 'SYNO.Core.ACL',
                'method'    => 'get',
                'version'   => '1',
                '_sid'      => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    /**
     * @param $folder_name
     * @return mixed
     */
    public function remove_from_acl($folder_name)
    {
        $params = array(
                'file_path'  => '/volume1/Shared/' . $folder_name,
                'files'      => '/volume1/Shared/' . $folder_name,
                'dirPaths'   => '/Shared/' . $folder_name,
                'change_acl' => 'true',
                'rules'      => json_encode(array()),
                'inherited'  => 'true',
                'acl_recur'  => 'false',
                'api'        => 'SYNO.Core.ACL',
                'method'     => 'set',
                'version'    => '1',
                '_sid'       => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    public function get_client_folders()
    {
        $params = array(
                'offset'         => '0',
                'limit'          => '1000',
                'sort_by'        => 'name',
                'sort_direction' => 'ASC',
                'action'         => 'list',
                'check_dir'      => 'true',
                //'additional'     => json_encode(["real_path", "size", "owner", "time", "perm", "type", "mount_point_type", "description", "indexed"]),
                'filetype'       => 'all',
                'folder_path'    => '/Shared',
                'api'            => 'SYNO.FileStation.List',
                'method'         => 'list',
                'version'        => '2',
                '_sid'           => $this->sid
        );
        $response = $this->send_request($this->url, $params);

        $folders = [];

        if (isset($response['data']['files']))
        {
            foreach ($response['data']['files'] as $item)
            {
                if ($item['isdir'] == TRUE)
                {
                    $folders[] = ['folder_name' => $item['name'], 'folder_path' => $item['path']];
                }
            }
        }
        return $folders;
    }

    /**
     * @param $folder_name
     * @return bool|mixed
     */
    public function create_client_folder($folder_name)
    {
        $params = array(
                'folder_path'  => '/Shared',
                'name'         => $folder_name,
                'force_parent' => 'false',
                'api'          => 'SYNO.FileStation.CreateFolder',
                'method'       => 'create',
                'version'      => '2',
                '_sid'         => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    /**
     * @return array|bool
     */
    public function list_groups()
    {
        $params = array(
                'offset'    => '0',
                'limit'     => '-1',
                'name_only' => 'false',
                'type'      => 'local',
                'api'       => 'SYNO.Core.Group',
                'method'    => 'list',
                'version'   => '1',
                '_sid'      => $this->sid
        );
        $response = $this->send_request($this->url, $params);

        $groups = [];

        if (isset($response['data']['groups']))
        {
            foreach ($response['data']['groups'] as $item)
            {
                $groups[] = ['name' => $item['name'], 'gid' => $item['gid']];
            }
        }
        return $groups;
    }

    /**
     * @param $group_name
     * @return bool
     */
    public function is_group($group_name)
    {
        $params = array(
                'name'    => $this->normalize(substr($group_name, 0, 32)),
                'api'     => 'SYNO.Core.Group',
                'method'  => 'get',
                'version' => '1',
                '_sid'    => $this->sid
        );

        $response = $this->send_request($this->url, $params);

        if (empty($response['data']['errors']))
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * @param $group_name
     * @return bool|mixed
     */
    public function create_group($group_name)
    {
        $group_name = $this->normalize(substr($group_name, 0, 32));

        if (!$this->is_group($group_name))
        {
            $params = array(
                    'api'     => 'SYNO.Core.Group',
                    'method'  => 'create',
                    'version' => '1',
                    'name'    => $group_name,
                    '_sid'    => $this->sid
            );
            return $this->send_request($this->url, $params);
        }
    }

    /**
     * @param $group_name
     * @return array
     */
    public function get_users_in_group($group_name)
    {
        $params = array(
                'ingroup' => 'true',
                'api'     => 'SYNO.Core.Group.Member',
                'method'  => 'list',
                'version' => '1',
                'group'    => $group_name,
                '_sid'    => $this->sid
        );
        $response = $this->send_request($this->url, $params);
        $users = [];
        if (isset($response['data']['users']))
        {
            foreach ($response['data']['users'] as $user)
            {
                $users[] = $user['name'];
            }
        }
        return $users;
    }

    /**
     * @param $group_name
     * @param $user_name
     * @return mixed
     */
    public function add_to_group($group_name, $user_name)
    {
        $params = array(
                'group'   => $this->normalize(substr($group_name, 0, 32)),//group name max length is 32
                'name'    => json_encode(array($this->normalize($user_name))),
                'api'     => 'SYNO.Core.Group.Member',
                'method'  => 'add',
                'version' => '1',
                '_sid'    => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    /**
     * @param $group_name
     * @param $user_name
     * @return mixed
     */
    public function remove_from_group($group_name, $user_name)
    {
        $params = array(
                'group'   => $this->normalize(substr($group_name, 0, 32)),
                'name'    => json_encode(array($this->normalize($user_name))),
                'api'     => 'SYNO.Core.Group.Member',
                'method'  => 'remove',
                'version' => '1',
                '_sid'    => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    /**
     * @param $group_name
     */
    public function remove_all_from_group($group_name)
    {
        $users = $this->get_users_in_group($group_name);

        if (!empty($users))
        {
            foreach ($users as $user)
            {
                $this->remove_from_group($group_name, $user);
            }
        }
    }

    /**
     * @param $group_name
     * @param $client_folder
     * @return mixed
     */
    public function add_permission($group_name, $client_folder)
    {
        $rules = array(
                array(
                        'owner_type'      => 'group',
                        'owner_name'      => $this->normalize(substr($group_name, 0, 32)),
                        'permission_type' => 'allow',
                        'permission'      => array(
                                'read_data'      => TRUE,
                                'write_data'     => TRUE,
                                'exe_file'       => TRUE,
                                'append_data'    => TRUE,
                                'delete'         => TRUE,
                                'delete_sub'     => TRUE,
                                'read_attr'      => TRUE,
                                'write_attr'     => TRUE,
                                'read_ext_attr'  => TRUE,
                                'write_ext_attr' => TRUE,
                                'read_perm'      => TRUE,
                                'change_perm'    => FALSE,
                                'take_ownership' => FALSE
                        ),
                        'inherit'         => array(
                                'child_files'     => TRUE,
                                'child_folders'   => TRUE,
                                'this_folder'     => TRUE,
                                'all_descendants' => TRUE,
                        )
                )
        );

        $params = array(
                'file_path'  => '/volume1/Shared/' . $client_folder,
                'files'      => '/volume1/Shared/' . $client_folder,
                'dirPaths'   => '/Shared/' . $client_folder,
                'change_acl' => 'true',
                'rules'      => json_encode($rules),
                'inherited'  => 'true',
                'acl_recur'  => 'false',
                'api'        => 'SYNO.Core.ACL',
                'method'     => 'set',
                'version'    => '1',
                '_sid'       => $this->sid
        );

        return $this->send_request($this->url, $params);
    }

    /**
     * @param $user_name
     * @return bool
     */
    public function is_user($user_name)
    {
        $params = array(
                'name'    => $this->normalize($user_name),
                'api'     => 'SYNO.Core.User',
                'method'  => 'get',
                'version' => '1',
                '_sid'    => $this->sid
        );

        $response = $this->send_request($this->url, $params);

        if ($response['success'] == TRUE)
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }

    /**
     * @param $user_name
     * @param $password
     * @param $description
     * @param $email
     * @param string $send_password
     * @return mixed
     */
    public function create_user($user_name, $password, $description, $email, $send_password = 'false')
    {
        if (!$this->is_user($user_name))
        {
            $params = array(
                    'api'               => 'SYNO.Core.User',
                    'method'            => 'create',
                    'version'           => '1',
                    'name'              => $user_name,
                    'description'       => $description,
                    'email'             => $email,
                    'cannot_chg_passwd' => 'true',
                    'expired'           => 'normal',
                    'password'          => $password,
                    'notify_by_email'   => 'true',
                    'send_password'     => $send_password,
                    '_sid'              => $this->sid
            );

            return $this->send_request($this->url, $params);
        }
    }

    /**
     * @param $user_name
     * @param $password
     * @return mixed
     */
    public function change_password($user_name, $password)
    {
        if ($this->is_user($user_name))
        {
            $params = array(
                    'api'      => 'SYNO.Core.User',
                    'method'   => 'set',
                    'version'  => '1',
                    'name'     => $user_name,
                    'password' => $password,
                    '_sid'     => $this->sid
            );
            return $this->send_request($this->url, $params);
        }
    }

    /**
     * @param $user_name
     * @return mixed
     */
    public function disable_account($user_name)
    {
        if ($this->is_user($user_name))
        {
            $params = array(
                    'api'     => 'SYNO.Core.User',
                    'method'  => 'set',
                    'version' => '1',
                    'name'    => $user_name,
                    'expired' => 'now',
                    '_sid'    => $this->sid
            );
            return $this->send_request($this->url, $params);
        }
    }

    /**
     * @param $user_name
     * @return mixed
     */
    public function enable_account($user_name)
    {
        if ($this->is_user($user_name))
        {
            $params = array(
                    'api'     => 'SYNO.Core.User',
                    'method'  => 'set',
                    'version' => '1',
                    'name'    => $user_name,
                    'expired' => 'normal',
                    '_sid'    => $this->sid
            );
            return $this->send_request($this->url, $params);
        }
    }

    /**
     * @param $user_name
     * @param $email
     * @return mixed
     */
    public function change_email($user_name, $email)
    {
        $params = array(
                'api'     => 'SYNO.Core.User',
                'method'  => 'set',
                'version' => '1',
                'name'    => $user_name,
                'email'   => $email,
                '_sid'    => $this->sid
        );
        return $this->send_request($this->url, $params);
    }

    /**
     * @param $url
     * @param $params
     * @return mixed
     */
    private function send_request($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $response = curl_exec($ch);
        $curlerror = curl_errno($ch);
        if (!empty($curlerror))
        {
            log_message('error', 'curl error: ' . $curlerror);
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }

    /**
     * @param $value
     * @return mixed
     */
    private function normalize($value)
    {
        $chars = array(',', '`', '~', '\'', '"', ';', '!', '@', '#', '$', '^', '*', '[', ']', '=', '+', '/', '\\', '{', '}', '<', '>', '?', '&', '(', ')');
        return str_replace($chars, '', $value);
    }
}
