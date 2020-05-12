<?php

class CRM_Uepalimport_Helper {
  public static function onEnd(CRM_Queue_TaskContext $ctx) {
    CRM_Core_Session::setStatus('Done.', 'Queue', 'success');
  }

  public static function process_tmp_paroisses_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_paroisses
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      $params = [
        'organization_name' => $dao->organization_name,
        'contact_type' => 'Organization',
        'contact_sub_type' => ['paroisse'],
      ];
      $result = civicrm_api3('Contact', 'get', $params);
      if ($result['count'] == 0) {
        // create the contact
        $params['external_identifier'] = $dao->external_identifier;

        // add address
        if ($dao->street_address) {
          $params['api.address.create'] = ['street_address' => $dao->street_address];
          $params['api.address.create']['location_type_id'] = 2; // work
          if ($dao->postal_code) {
            $params['api.address.create']['postal_code'] = $dao->postal_code;
            $params['api.address.create']['state_province_id'] = self::getFrenchDepartment($dao->postal_code);
          }
          if ($dao->city) {
            $params['api.address.create']['city'] = $dao->city;
          }
          if ($dao->supplemental_address_1) {
            $params['api.address.create']['supplemental_address_1'] = $dao->supplemental_address_1;
          }
          $params['api.address.create']['country_id'] = 1076;
        }

        // add phone
        if ($dao->phone) {
          $params['api.phone.create'] = [
            'phone' => $dao->phone,
            'location_type_id' => 2, // work
            'phone_type_id' => 1,
          ];
        }

        // add fax
        if ($dao->fax) {
          $suffix = '';
          if (array_key_exists('api.phone.create', $params)) {
            $suffix = '.2';
          }
          $params["api.phone.create$suffix"] = [
            'phone' => $dao->fax,
            'location_type_id' => 2, // work
            'phone_type_id' => 3, // fax
          ];
        }

        // add email
        if ($dao->email) {
          $params['api.email.create'] = [
            'email' => $dao->email,
            'location_type_id' => 2, // work
          ];
        }

        // add website
        if ($dao->website) {
          $params['api.website.create'] = [
            'url' => $dao->website,
            'location_type_id' => 1, // work
          ];
        }

        // add custom fields
        $config = new CRM_Uepalconfig_Config();
        if ($dao->custom_field_eglise) {
          $params['custom_' . $config->getCustomField_paroisseDetailEglise()['id']] = $dao->custom_field_eglise;
        }
        if ($dao->custom_field_nombre_paroissiens) {
          $params['custom_' . $config->getCustomField_paroisseDetailNombreParoissiens()['id']] = $dao->custom_field_nombre_paroissiens;
        }
        if ($dao->custom_field_nombre_electeurs) {
          $params['custom_' . $config->getCustomField_paroisseDetailNombreElecteurs()['id']] = $dao->custom_field_nombre_electeurs;
        }
        if ($dao->custom_field_theologie_reforme) {
          $params['custom_' . $config->getCustomField_paroisseDetailTheologie()['id']] = [$dao->custom_field_theologie_reforme . 'e'];
        }
        if ($dao->custom_field_theologie_lutherien) {
          $params['custom_' . $config->getCustomField_paroisseDetailTheologie()['id']] = [$dao->custom_field_theologie_lutherien . 'ne'];
        }

        $result = civicrm_api3('Contact', 'create', $params);
      }
    }

    return TRUE;
  }

  public static function process_tmp_individus_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_individus
      WHERE
        id = '$id'
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      if (self::getContactByExternalId($dao->id) === FALSE) {
        $params = [
          'contact_type' => 'Individual',
          'external_identifier' => $dao->id,
          'first_name' => $dao->first_name,
          'last_name' => $dao->last_name,
          'gender_id' => $dao->gender == 'Féminin' ? 1 : 2,
          'prefix_id' => $dao->prefix == 'Madame' ? 1 : 3,
          'job_title' => $dao->job_title,
          'source' => $dao->source,
        ];

        if ($dao->birth_year) {
          $params['birth_date'] = $dao->birth_year . '-01-01';
        }

        // add address
        if ($dao->street_address) {
          $params['api.address.create'] = ['street_address' => $dao->street_address];
          $params['api.address.create']['location_type_id'] = 1; // home
          if ($dao->postal_code) {
            $params['api.address.create']['postal_code'] = $dao->postal_code;
            $params['api.address.create']['state_province_id'] = self::getFrenchDepartment($dao->postal_code);
          }
          if ($dao->city) {
            $params['api.address.create']['city'] = $dao->city;
          }
          if ($dao->supplemental_address_1) {
            $params['api.address.create']['supplemental_address_1'] = $dao->supplemental_address_1;
          }
          $params['api.address.create']['country_id'] = 1076;
        }

        // add phone
        if ($dao->home_phone) {
          $params['api.phone.create'] = [
            'phone' => $dao->home_phone,
            'location_type_id' => 1, // home
            'phone_type_id' => 1,
          ];
        }

        // add mobile phone
        if ($dao->home_mobile_phone) {
          $suffix = '';
          if (array_key_exists('api.phone.create', $params)) {
            $suffix = '.2';
          }
          $params["api.phone.create$suffix"] = [
            'phone' => $dao->home_mobile_phone,
            'location_type_id' => 1, // home
            'phone_type_id' => 2,
          ];
        }

        // add email
        if ($dao->home_email) {
          $params['api.email.create'] = [
            'email' => $dao->home_email,
            'location_type_id' => 1, // home
          ];
        }

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);

        $config = new CRM_Uepalconfig_Config();

        // add the CP relationship and the others
        if ($dao->cp_member_id) {
          $relTypeId = $config->getRelationshipType_estMembreEluDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_member_id, $relTypeId, $dao->cp_member_start_date, $dao->cp_member_end_date);
        }

        if ($dao->cp_president_id) {
          $relTypeId = $config->getRelationshipType_estPresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_president_id, $relTypeId, '', '');
        }

        if ($dao->cp_vice_president_id) {
          $relTypeId = $config->getRelationshipType_estVicePresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_vice_president_id, $relTypeId, '', '');
        }

        if ($dao->cp_secratary_id) {
          $relTypeId = $config->getRelationshipType_estSecretaireDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_secratary_id, $relTypeId, '', '');
        }

        if ($dao->cp_treasurer_id) {
          $relTypeId = $config->getRelationshipType_estTresorierDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_treasurer_id, $relTypeId, '', '');
        }

        if ($dao->cp_invited_id) {
          $relTypeId = $config->getRelationshipType_estMembreInviteDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_invited_id, $relTypeId, '', '');
        }
      }
    }

    return TRUE;
  }

  public static function createRelationship($contactIdA, $exaternalContactIdB, $relationshipTypeId, $startDate, $endDate) {
    // get the corresponding contact
    $targetContact = self::getContactByExternalId($exaternalContactIdB);
    if ($targetContact === FALSE) {
      watchdog('import', "cannot find contact with external id = " . $exaternalContactIdB);
    }
    else {
      $params = [
        'contact_id_a' => $contactIdA,
        'contact_id_b' => $targetContact['id'],
        'is_active' => 1,
        'relationship_type_id' => $relationshipTypeId,
      ];

      if ($startDate) {
        $convertedDate = strtotime($startDate);
        if ($convertedDate) {
          $params['start_date'] = date("Y-m-d", $convertedDate);
        }
      }

      if ($endDate) {
        $convertedDate = strtotime($endDate);
        if ($convertedDate) {
          $params['end_date'] = date("Y-m-d", $convertedDate);
          if ($convertedDate < time()) {
            $params['is_active'] = 0;
          }
        }
      }

      civicrm_api3('Relationship', 'create', $params);
    }
  }

  public static function getContactByExternalId($id) {
    try {
      $contact = civicrm_api3('Contact', 'getsingle', ['external_identifier' => $id]);
      return $contact;
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  public static function getFrenchDepartment($postalCode) {
    $sql = "select id from civicrm_state_province where country_id = 1076 and abbreviation = %1";
    $sqlParams = [
      1 => [substr($postalCode, 0, 2), 'String'],
    ];
    return CRM_Core_DAO::singleValueQuery($sql, $sqlParams);
  }


}
