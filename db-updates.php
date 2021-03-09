<?php

use function MapasCulturais\__table_exists as MapasCulturais__table_exists;
use function MapasCulturais\__try as MapasCulturais__try;

$app = MapasCulturais\App::i();
$em = $app->em;
$conn = $em->getConnection();

return array(
  'create table secultce_payments' => function() use($conn) {

    if (MapasCulturais__table_exists("secultce_payment")) {
      echo "TABLE secultce_payment ALREADY EXISTS";
      return true;
    }

    MapasCulturais__try("
      CREATE TABLE secultce_payment (
        id INT NOT NULL, 
        registration_id INT NOT NULL,
        installment INT,
        value FLOAT,
        status INT,
        payment_date TIMESTAMP,
        generate_file_date TIMESTAMP,
        sent_date TIMESTAMP,
        return_date TIMESTAMP,
        payment_file_id INT,
        return_file_id INT,
        error TEXT,

        PRIMARY KEY ('id')
      )
    ");

    MapasCulturais__try("
      CREATE SEQUENCE secultce_payment_id_seq INCREMENT BY 1 MINVALUE 1 START 1
    ");

    MapasCulturais__try("
      ALTER TABLE secultce_payment ADD CONSTRAINT secultce_payment_fk_registration_id FOREIGN KEY (registration_id) REFERENCES registration (id)
    ");

    MapasCulturais__try("
      ALTER TABLE secultce_payment ADD CONSTRAINT secultce_payment_fk_payment_file_id FOREIGN KEY (payment_file_id) REFERENCES file (id)
    ");

    MapasCulturais__try("
      ALTER TABLE secultce_payment ADD CONSTRAINT secultce_payment_fk_return_file_id FOREIGN KEY (return_file_id) REFERENCES file (id)
    ");
  },
  'create table secultce_payments_history' => function() use($conn){
    if (MapasCulturais__table_exists("secultce_payments_history")) {
      echo "TABLE secultce_payments_history ALREADY EXISTS";
      return true;
    }

    MapasCulturais__try("
      CREATE TABLE secultce_payment_history (
        id INT NOT NULL,
        payment_id INT,
        files_id INT,
        action VARCHAR(255),
        result TEXT,
        file_date TIMESTAMP,
        payment_date TIMESTAMP
      )    
    ");

    MapasCulturais__try("
      CREATE SEQUENCE secultce_payment_history_id_seq INCREMENT BY 1 MINVALUE 1 START 1
    ");

    MapasCulturais__try("
      ALTER TABLE secultce_payment_history ADD CONSTRAINT secultce_payment_history_fk_payment_id FOREIGN KEY (payment_id) REFERENCES secultce_payment (id)
    ");
  }
);