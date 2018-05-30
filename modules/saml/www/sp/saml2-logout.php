<?php

/**
 * Logout endpoint handler for SAML SP authentication client.
 *
 * This endpoint handles both logout requests and logout responses.
 */

if (!array_key_exists('PATH_INFO', $_SERVER)) {
    throw new \SimpleSAML\Error\BadRequest('Missing authentication source ID in logout URL');
}

$sourceId = substr($_SERVER['PATH_INFO'], 1);

$source = SimpleSAML_Auth_Source::getById($sourceId);
if ($source === null) {
    throw new Exception('Could not find authentication source with id ' . $sourceId);
}
if (!($source instanceof sspmod_saml_Auth_Source_SP)) {
    throw new \SimpleSAML\Error\Exception('Source type changed?');
}

try {
    $binding = \SAML2\Binding::getCurrentBinding();
} catch (Exception $e) { // TODO: look for a specific exception
    // This is dirty. Instead of checking the message of the exception, \SAML2\Binding::getCurrentBinding() should throw
    // an specific exception when the binding is unknown, and we should capture that here
    if ($e->getMessage() === 'Unable to find the current binding.') {
        throw new \SimpleSAML\Error\Error('SLOSERVICEPARAMS', $e, 400);
    } else {
        throw $e; // do not ignore other exceptions!
    }
}
$message = $binding->receive();

$idpEntityId = $message->getIssuer();
if ($idpEntityId === null) {
    // Without an issuer we have no way to respond to the message.
    throw new \SimpleSAML\Error\BadRequest('Received message on logout endpoint without issuer.');
}

$spEntityId = $source->getEntityId();

$metadata = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();
$idpMetadata = $source->getIdPMetadata($idpEntityId);
$spMetadata = $source->getMetadata();

sspmod_saml_Message::validateMessage($idpMetadata, $spMetadata, $message);

$destination = $message->getDestination();
if ($destination !== null && $destination !== \SimpleSAML\Utils\HTTP::getSelfURLNoQuery()) {
    throw new \SimpleSAML\Error\Exception('Destination in logout message is wrong.');
}

if ($message instanceof \SAML2\LogoutResponse) {

    $relayState = $message->getRelayState();
    if ($relayState === null) {
        // Somehow, our RelayState has been lost.
        throw new \SimpleSAML\Error\BadRequest('Missing RelayState in logout response.');
    }

    if (!$message->isSuccess()) {
        SimpleSAML\Logger::warning('Unsuccessful logout. Status was: ' . sspmod_saml_Message::getResponseError($message));
    }

    $state = SimpleSAML_Auth_State::loadState($relayState, 'saml:slosent');
    $state['saml:sp:LogoutStatus'] = $message->getStatus();
    SimpleSAML_Auth_Source::completeLogout($state);

} elseif ($message instanceof \SAML2\LogoutRequest) {

    SimpleSAML\Logger::debug('module/saml2/sp/logout: Request from ' . $idpEntityId);
    SimpleSAML\Logger::stats('saml20-idp-SLO idpinit ' . $spEntityId . ' ' . $idpEntityId);

    if ($message->isNameIdEncrypted()) {
        try {
            $keys = sspmod_saml_Message::getDecryptionKeys($idpMetadata, $spMetadata);
        } catch (Exception $e) {
            throw new \SimpleSAML\Error\Exception('Error decrypting NameID: ' . $e->getMessage());
        }

        $blacklist = sspmod_saml_Message::getBlacklistedAlgorithms($idpMetadata, $spMetadata);

        $lastException = null;
        foreach ($keys as $i => $key) {
            try {
                $message->decryptNameId($key, $blacklist);
                SimpleSAML\Logger::debug('Decryption with key #' . $i . ' succeeded.');
                $lastException = null;
                break;
            } catch (Exception $e) {
                SimpleSAML\Logger::debug('Decryption with key #' . $i . ' failed with exception: ' . $e->getMessage());
                $lastException = $e;
            }
        }
        if ($lastException !== null) {
            throw $lastException;
        }
    }

    $nameId = $message->getNameId();
    $sessionIndexes = $message->getSessionIndexes();

    $numLoggedOut = sspmod_saml_SP_LogoutStore::logoutSessions($sourceId, $nameId, $sessionIndexes);
    if ($numLoggedOut === false) {
        /* This type of logout was unsupported. Use the old method. */
        $source->handleLogout($idpEntityId);
        $numLoggedOut = count($sessionIndexes);
    }

    /* Create and send response. */
    $lr = sspmod_saml_Message::buildLogoutResponse($spMetadata, $idpMetadata);
    $lr->setRelayState($message->getRelayState());
    $lr->setInResponseTo($message->getId());

    if ($numLoggedOut < count($sessionIndexes)) {
        SimpleSAML\Logger::warning('Logged out of ' . $numLoggedOut  . ' of ' . count($sessionIndexes) . ' sessions.');
    }

    $dst = $idpMetadata->getEndpointPrioritizedByBinding('SingleLogoutService', array(
        \SAML2\Constants::BINDING_HTTP_REDIRECT,
        \SAML2\Constants::BINDING_HTTP_POST)
    );

    if (!$binding instanceof \SAML2\SOAP) {
        $binding = \SAML2\Binding::getBinding($dst['Binding']);
        if (isset($dst['ResponseLocation'])) {
            $dst = $dst['ResponseLocation'];
        } else {
            $dst = $dst['Location'];
        }
        $binding->setDestination($dst);
    }
    $lr->setDestination($dst);

    $binding->send($lr);
} else {
    throw new \SimpleSAML\Error\BadRequest('Unknown message received on logout endpoint: ' . get_class($message));
}
