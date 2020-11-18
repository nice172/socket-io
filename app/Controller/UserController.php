<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Cache\ApplyNumCache;
use App\Cache\FriendRemarkCache;
use App\Component\Sms;
use App\Helper\ValidateHelper;
use App\Kernel\SocketIO;
use App\Model\Users;
use App\Model\UsersChatList;
use App\Model\UsersFriends;
use App\Service\UserFriendService;
use App\Service\UserService;
use Hyperf\Redis\RedisFactory;

class UserController extends AbstractController
{
    private $service;

    private $friendService;

    public function __construct(UserService $service, UserFriendService $friendService)
    {
        $this->service       = $service;
        $this->friendService = $friendService;
        parent::__construct();
    }

    /**
     * 用户相关设置
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserSetting()
    {
        $user = $this->request->getAttribute('user');
        $info = $this->service->findById($user['id'], ['id', 'nickname', 'avatar', 'motto', 'gender']);
        return $this->response->success('success', [
            'user_info' => [
                'uid'      => $info->id,
                'nickname' => $info->nickname,
                'avatar'   => $info->avatar,
                'motto'    => $info->motto,
                'gender'   => $info->gender,
            ],
            'setting'   => [
                'theme_mode'            => '',
                'theme_bag_img'         => '',
                'theme_color'           => '',
                'notify_cue_tone'       => '',
                'keyboard_event_notify' => '',
            ]
        ]);
    }

    /**
     * 获取好友申请未读数
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getApplyUnreadNum()
    {
        $user = $this->request->getAttribute('user');
        return $this->response->success('success', [
            'unread_num' => ApplyNumCache::get($user['id'])
        ]);
    }

    /**
     * 获取我的信息
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserDetail()
    {
        $user     = $this->request->getAttribute('user');
        $userInfo = $this->service->findById($user['id'], ['mobile', 'nickname', 'avatar', 'motto', 'email', 'gender']);
        return $this->response->success('success', [
            'mobile'   => $userInfo->mobile,
            'nickname' => $userInfo->nickname,
            'avatar'   => $userInfo->avatar,
            'motto'    => $userInfo->motto,
            'email'    => $userInfo->email,
            'gender'   => $userInfo->gender
        ]);
    }

    /**
     * 获取我的好友列表
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserFriends()
    {
        $user  = $this->request->getAttribute('user');
        $rows  = UsersFriends::getUserFriends($user['id']);
        $redis = di(RedisFactory::class)->get(env('CLOUD_REDIS'));
        $cache = array_keys($redis->hGetAll(SocketIO::HASH_UID_TO_FD_PREFIX));

        foreach ($rows as $k => $row) {
            $rows[$k]['online'] = in_array($row['id'], $cache) ? true : false;
        }
        return $this->response->success('success', $rows);
    }

    /**
     * 编辑我的信息
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function editUserDetail()
    {
        $user   = $this->request->getAttribute('user');
        $params = ['nickname', 'avatar', 'motto', 'gender'];
        if (!$this->request->has($params) || ValidateHelper::isInteger($this->request->post('gender'))) {
            return $this->response->parmasError('参数错误!');
        }
        //TODO 编辑个人资料
        //待驾照拿到之后继续更新
        $bool = Users::where('id', $user['id'])->update($this->request->inputs($params));
        return $bool ? $this->response->success('个人信息修改成功') : $this->response->parmasError('个人信息修改失败');
    }

    /**
     * 修改我的密码
     *
     * @return \Psr\Http\Message\ResponseInterface
     */

    public function editUserPassword()
    {
        $user = $this->request->getAttribute('user');
        if (!$this->request->has(['old_password', 'new_password'])) {
            return $this->response->parmasError('参数错误!');
        }
        if (!ValidateHelper::checkPassword($this->request->input('new_password'))) {
            return $this->response->error('新密码格式错误(8~16位字母加数字)');
        }
        $info = $this->service->findById($user['id'], ['id', 'password', 'mobile']);
        if (!$this->service->checkPassword($info->password, $this->request->input('password'))) {
            return $this->response->error('旧密码验证失败!');
        }
        $bool = $this->service->resetPassword($info->mobile, $this->request->input('new_password'));
        return $bool ? $this->response->success('密码修改成功...') : $this->response->parmasError('密码修改失败...');
    }

    /**
     * 获取我的好友申请记录
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getFriendApplyRecords()
    {
        $page     = $this->request->input('page', 1);
        $pageSize = $this->request->input('page_size', 10);
        $user     = $this->request->getAttribute('user');
        $data     = $this->friendService->findApplyRecords($user['id'], $page, $pageSize);
        ApplyNumCache::del($user['id']);
        return $this->response->success('success', $data);
    }

    /**
     *
     * 发送添加好友申请
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendFriendApply()
    {
        $friendId = $this->request->post('friend_id');
        $remarks  = $this->request->post('remarks', '');
        $user     = $this->request->getAttribute('user');
        if (!ValidateHelper::isInteger($friendId)) {
            return $this->response->parmasError('参数错误!');
        }

        $bool = $this->friendService->addFriendApply($user['id'], $friendId, $remarks);
        if (!$bool) {
            return $this->response->error('发送好友申请失败...');
        }
        $redis = di(RedisFactory::class)->get(env('CLOUD_REDIS'));

        //判断对方是否在线。如果在线发送消息通知
        if ($redis->hGet(SocketIO::HASH_UID_TO_FD_PREFIX, (string)$friendId)) {

        }
        // 好友申请未读消息数自增
        ApplyNumCache::setInc($friendId);
        return $this->response->success('发送好友申请成功...');
    }

    /**
     * 处理好友的申请
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handleFriendApply()
    {
        $applyId = $this->request->post('apply_id');
        $remarks = $this->request->post('remarks', '');
        $user    = $this->request->getAttribute('user');
        if (!ValidateHelper::isInteger($applyId)) {
            return $this->response->parmasError('参数错误!');
        }
        $bool = $this->friendService->handleFriendApply($user['id'], $applyId, $remarks);
        //判断是否是同意添加好友
        if ($bool) {
            //... 推送处理消息
        }
        return $bool ? $this->response->success('处理完成...') : $this->response->error('处理失败，请稍后再试...');
    }

    /**
     * 删除好友申请记录
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function deleteFriendApply()
    {
        $applyId = $this->request->post('apply_id');
        $user    = $this->request->getAttribute('user');
        if (!ValidateHelper::isInteger($applyId)) {
            return $this->response->parmasError('参数错误!');
        }
        $bool = $this->friendService->delFriendApply($user['id'], $applyId);
        return $bool ? $this->response->success('删除成功...') : $this->response->parmasError('删除失败...');
    }

    /**
     *
     * 编辑好友备注信息
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function editFriendRemark()
    {
        $user     = $this->request->getAttribute('user');
        $friendId = $this->request->post('friend_id');
        $remarks  = $this->request->post('remarks', '');
        if (!ValidateHelper::isInteger($friendId) || empty($remarks)) {
            return $this->response->parmasError('参数错误!');
        }
        $bool = $this->friendService->editFriendRemark($user['id'], $friendId, $remarks);
        if ($bool) {
            FriendRemarkCache::set($user['id'], $friendId, $remarks);
        }
        return $bool ? $this->response->success('备注修改成功...') : $this->response->error('备注修改失败，请稍后再试...');
    }

    /**
     *
     * 获取指定用户信息
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function searchUserInfo()
    {
        $user   = $this->request->getAttribute('user');
        $uid    = $this->request->post('user_id', 0);
        $mobile = $this->request->post('mobile', '');
        $where  = [];
        if (ValidateHelper::isInteger($uid)) {
            $where['uid'] = $uid;
        } else {
            if (ValidateHelper::isPhone($mobile)) {
                $where['mobile'] = $mobile;
            } else {
                return $this->response->parmasError('参数错误!');
            }
        }
        if ($data = $this->userService->searchUserInfo($where, $user['id'])) {
            return $this->response->success('success', $data);
        }
        return $this->response->fail(303, 'success');
    }

    /**
     *
     * 获取用户群聊列表
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUserGroups()
    {
        $user = $this->request->getAttribute('user');
        $rows = $this->service->getUserChatGroups($user['id']);
        return $this->response->success('success', $rows);
    }

    /**
     * 更换用户手机号
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function editUserMobile()
    {
        $sms_code = $this->request->post('sms_code', '');
        $mobile   = $this->request->post('mobile', '');
        $password = $this->request->post('password', '');
        if (!ValidateHelper::isPhone($mobile)) {
            return $this->response->error('手机号格式不正确');
        }
        if (empty($sms_code)) {
            return $this->response->error('短信验证码不正确');
        }
        if (!di(Sms::class)->check('change_mobile', $mobile, $sms_code)) {
            return $this->response->error('验证码填写错误...');
        }
        $user = $this->request->getAttribute('user');
        if (!$this->service->checkPassword($password, Users::where('id', $user['id'])->value('password'))) {
            return $this->response->error('账号密码验证失败');
        }
        [$bool, $message] = $this->service->changeMobile($user['id'], $mobile);
        if ($bool) {
            di(Sms::class)->delCode('change_mobile', $mobile);
        }
        return $bool ? $this->response->success('手机号更换成功') : $this->response->error(($message));
    }

    /**
     * 修改手机号发送验证码
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendMobileCode()
    {
        $user = $this->request->getAttribute('user');
        if (in_array($user['id'], [2054, 2055])) {
            return $this->response->parmasError('测试账号不支持修改手机号');
        }

        $mobile = $this->request->post('mobile', '');
        if (!ValidateHelper::isPhone($mobile)) {
            return $this->response->parmasError('手机号格式不正确');
        }

        if (Users::where('mobile', $mobile)->exists()) {
            return $this->response->error('手机号已被他人注册');
        }

        $data = ['is_debug' => true];
        [$isTrue, $result] = di(Sms::class)->send('change_mobile', $mobile);
        if ($isTrue) {
            $data['sms_code'] = $result['data']['code'];
        } else {
            // ... 处理发送失败逻辑，当前默认发送成功
        }
        return $this->response->success('验证码发送成功...', $data);
    }

    /**
     * 解除好友关系
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function removeFriend()
    {
        $friendId = $this->request->post('friend_id');
        $user     = $this->request->getAttribute('user');
        if (!ValidateHelper::isInteger($user['id'])) {
            return $this->response->parmasError('参数错误!');
        }

        if (!$this->friendService->removeFriend($user['id'], $friendId)) {
            return $this->response->error('解除好友失败...');
        }

        //删除好友会话列表
        UsersChatList::delItem($user['id'], $friendId, 2);
        UsersChatList::delItem($friendId, $user['id'], 2);

        return $this->response->success('success');
    }

    /**
     * //TODO 发送绑定邮箱的验证码
     */
    public function sendChangeEmailCode()
    {

    }

    /**
     * 修改用户邮箱接口
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function editUserEmail()
    {
        $email = $this->request->post('email', '');
        $email_code = $this->request->post('email_code', '');
        $password = $this->request->post('password', '');
        if (empty($email) || empty($email_code) || empty($password)) {
            return $this->response->parmasError();
        }
        //TODO 验证邮箱
    }

}