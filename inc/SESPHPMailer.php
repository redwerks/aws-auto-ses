<?php

use Aws\Ses\Exception\SesException;

/**
 * Augments PHPMailer with a SES Mailer handler.
 * @note This class doesn't modify other mailers so it does not need to be restored
 *	   to a normal PHPMailer instance for WordPress to send non-SES emails.
 */
class SESPHPMailer extends PHPMailer {

	public static function replace(PHPMailer $oldMailer) {
		$newMailer = new SESPHPMailer();

		foreach ( get_object_vars($oldMailer) as $prop => $value ) {
			if ( $prop === 'Version' ) continue;
			$newMailer->{$prop} = $value;
		}

		return $newMailer;
	}

	/**
	 * Send messages using SES.
	 * @return void
	 */
	public function isSES() {
		$this->Mailer = 'ses';
		$this->SESClient = awsautoses_sesclient();
	}

	/**
	 * Send mail using the $Sendmail program.
	 * @param string $header The message headers
	 * @param string $body The message body
	 * @see PHPMailer::$Sendmail
	 * @throws phpmailerException
	 * @access protected
	 * @return boolean
	 */
	protected function sesSend($header, $body) {
		if ( !$this->SESClient ) {
			throw new phpmailerException('SES Client not defined.', self::STOP_CRITICAL);
		}

		try {
			if ( $this->SingleTo ) {
				foreach ( $this->SingleToArray as $toAddr ) {
					$result = !!$this->SESClient->sendRawEmail(array(
						'Source' => $this->Sender ? $this->Sender : $this->From,
						'Destinations' => array($toAddr),
						'RawMessage' => array(
							'Data' => $header . "\n" . $body
						)
					));
					$this->doCallback($result, array($toAddr), $this->cc, $this->bcc, $this->Subject, $body, $this->From);
				}
			} else {
				$result = !!$this->SESClient->sendRawEmail(array(
					'Source' => $this->Sender ? $this->Sender : $this->From,
					'Destinations' => array_map(array($this, 'addrFormat'), $this->to),
					'RawMessage' => array(
						'Data' => $header . "\n" . $body
					)
				));
				$this->doCallback($result, $this->to, $this->cc, $this->bcc, $this->Subject, $body, $this->From);
			}
		} catch ( SesException $e ) {
			throw new phpmailerException($e->getMessage(), self::STOP_CRITICAL);
		}

		return true;
	}
}
