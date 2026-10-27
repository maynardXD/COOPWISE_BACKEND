<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Member_model extends Model
{
    protected $table = 'members';
    protected $fillable = [
        'user_id',
        'member_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'contact_number',
        'birth_date',
        'status'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
    }

    public function find_for_user($user_id)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->get();
    }

    public function next_member_number()
    {
        $latest = $this->db->table($this->table)
            ->select('member_number')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get();

        $next = $latest ? ((int) $latest['member_number'] + 1) : 1;
        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
