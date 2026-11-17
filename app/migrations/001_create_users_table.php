<?php
// This file creates or updates the 001 create users table database tables.


class Create_users_table {

    private $_lava;

    // This function sets up this controller and its database tools.
    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    // This function creates the database changes for this migration.
    public function up()
    {
        if ($this->_lava->dbforge->table_exists('users')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'email' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => FALSE,
                    'unique'     => TRUE,
                ],
                'password' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => FALSE,
                ],
                'role' => [
                    'type'       => 'ENUM',
                    'constraint' => "'admin','moderator','user'",
                    'null'       => FALSE,
                    'default'    => 'user',
                ],
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                    'default'    => 1,
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('email', name: 'email_idx')
            ->add_key('role', name: 'role_idx')
            ->create_table('users');
    }

    // This function removes the database changes made by this migration.
    public function down()
    {
        $this->_lava->dbforge->drop_table('users');
    }
}