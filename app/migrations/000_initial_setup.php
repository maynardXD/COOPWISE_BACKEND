<?php
// This file creates or updates the 000 initial setup database tables.


class Initial_setup {

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
        if ($this->_lava->dbforge->table_exists('migrations')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'migration' => [
                    'type'     => 'INT',
                    'null'     => FALSE,
                ],
                'applied_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('migration', unique: TRUE, name: 'migration_unique')
            ->create_table('migrations');
    }

    // This function removes the database changes made by this migration.
    public function down()
    {
        // Intentionally left as a no-op.
        // Dropping the migrations table would destroy all migration history
        // and break every subsequent rollback. Only drop manually if you
        // are wiping the entire database.
    }
}