<?php
/**
+-----------------------------------------------------------------------------------------------
* GouGuOPEN [ 左手研发，右手开源，未来可期！]
+-----------------------------------------------------------------------------------------------
* @Copyright (c) 2021~2024 http://www.gouguoa.com All rights reserved.
+-----------------------------------------------------------------------------------------------
* @Licensed 勾股OA，开源且可免费使用，但并不是自由软件，未经授权许可不能去除勾股OA的相关版权信息
+-----------------------------------------------------------------------------------------------
* @Author 勾股工作室 <hdm58@qq.com>
+-----------------------------------------------------------------------------------------------
*/

namespace app\finance\validate;
use think\Validate;
use think\facade\Db;

class TicketValidate extends Validate
{
    protected $rule = [
        'amount'           => 'require',
        'invoice_subject'  => 'require',
        'id'               => 'require'
    ];

    protected $message = [
        'amount.require'          => '发票金额不能为空',
        'id.require'              => '缺少更新条件',
        'invoice_subject.require' => '请选择发票主体',
    ];

    protected $scene = [
        'add'       => ['amount','invoice_subject'],
        'edit'      => ['id', 'amount','invoice_subject']
    ];
}