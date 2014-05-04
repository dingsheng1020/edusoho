<?php

namespace Application\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration,
    Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated Migration: Please modify to your need!
 */
class Version20140502181302 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        // this up() migration is autogenerated, please modify it to your needs
         $this->addSql("ALTER TABLE  `user_state` ADD  `totalVerifyUser`  int(10) NOT NULL DEFAULT '0'  COMMENT '记录生成时间' AFTER `totalUser` ");


    }

    public function down(Schema $schema)
    {
        // this down() migration is autogenerated, please modify it to your needs

    }
}
