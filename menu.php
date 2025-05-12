<?php
require_once 'sms.php';

class Menu {
    protected $text;
    protected $sessionId;
    protected $phoneNumber;
    protected $conn;

    function __construct($text, $sessionId, $phoneNumber, $conn) {
        $this->text = $text;
        $this->sessionId = $sessionId;
        $this->phoneNumber = $phoneNumber;
        $this->conn = $conn;
    }

    private function sendSMSNotification($message) {
        $sms = new Sms();
        $sms->sendSMS($message, $this->phoneNumber);
    }

    public function mainMenuUnregistered() {
        echo "CON Welcome to Adele MOMO\n1. Register";
    }

    public function menuRegister($textArray) {
        $level = count($textArray);

        if ($level == 1) {
            echo "CON Enter your full name";
        } elseif ($level == 2) {
            echo "CON Enter your PIN";
        } elseif ($level == 3) {
            echo "CON Re-enter your PIN";
        } elseif ($level == 4) {
            $name = $textArray[1];
            $pin = $textArray[2];
            $confirmPin = $textArray[3];

            if ($pin !== $confirmPin) {
                echo "END PINs do not match. Retry";
                return;
            }

            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare("INSERT INTO users (phone_number, full_name, pin, balance) VALUES (?, ?, ?, 5000)");
            $stmt->execute([$this->phoneNumber, $name, $hashedPin]);

            // Send SMS notification
            $message = "Hello $name, your registration was successful.";
            $this->sendSMSNotification($message);

            echo "END Dear $name, you have successfully registered.";
        }
    }

    public function mainMenuRegistered() {
        echo "CON Welcome back to Adele MOMO\n1. Send Money\n2. Withdraw Money\n3. Check Balance\n4. Deposit Money";
    }

    public function menuSendMoney($textArray) {
    $level = count($textArray);

    if ($level == 1) {
        echo "CON Enter recipient phone number";
    } elseif ($level == 2) {
        echo "CON Enter amount";
    } elseif ($level == 3) {
        echo "CON Enter PIN";
    } elseif ($level == 4) {
        list(, $recipient, $amount, $pin) = $textArray;

        // Fetch sender details
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
        $stmt->execute([$this->phoneNumber]);
        $sender = $stmt->fetch();

        if (!$sender || !password_verify($pin, $sender['pin'])) {
            echo "END Incorrect PIN.";
            return;
        }

        // Fetch recipient details
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
        $stmt->execute([$recipient]);
        $receiver = $stmt->fetch();

        if (!$receiver) {
            echo "END Recipient does not exist.";
            return;
        }

        // Save details in session or database for confirmation stage
        echo "CON You are about to send $amount Rwf to {$receiver['full_name']}.\n";
        echo "1. Confirm\n2. Cancel\n3. Back\n4. Main Menu";
    } elseif ($level == 5) {
        list(, $recipient, $amount, $pin, $option) = $textArray;
        $option = intval($option);

        if ($option === 1) {
            // Confirm and process
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$this->phoneNumber]);
            $sender = $stmt->fetch();

            if (!$sender || !password_verify($pin, $sender['pin'])) {
                echo "END Incorrect PIN.";
                return;
            }

            $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$recipient]);
            $receiver = $stmt->fetch();

            if (!$receiver) {
                echo "END Recipient not found.";
                return;
            }

            if ($sender['balance'] < $amount) {
                echo "END Insufficient balance.";
                return;
            }

            // Perform transaction
            $this->conn->beginTransaction();

            $this->conn->prepare("UPDATE users SET balance = balance - ? WHERE phone_number = ?")
                ->execute([$amount, $this->phoneNumber]);

            $this->conn->prepare("UPDATE users SET balance = balance + ? WHERE phone_number = ?")
                ->execute([$amount, $recipient]);

            $this->conn->prepare("INSERT INTO transactions (sender_phone, recipient_phone, amount, transaction_type) VALUES (?, ?, ?, 'SEND')")
                ->execute([$this->phoneNumber, $recipient, $amount]);

            $this->conn->commit();

            $message = "You sent $amount Rwf to {$receiver['full_name']} ($recipient).";
            $this->sendSMSNotification($message);

            echo "END You have sent $amount Rwf to {$receiver['full_name']} successfully.";
        } elseif ($option === 2) {
            echo "END Transaction cancelled.";
        } else {
            echo "END Invalid option.";
        }
    }
}


    public function menuCheckBalance($textArray) {
        $level = count($textArray);

        if ($level == 1) {
            echo "CON Enter your PIN";
        } elseif ($level == 2) {
            $pin = $textArray[1];

            $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$this->phoneNumber]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pin, $user['pin'])) {
                echo "END Incorrect PIN.";
                return;
            }

            $balance = $user['balance'];
            $message = "Hello, your current balance is: $balance Rwf.";

            // Send SMS notification
            $this->sendSMSNotification($message);

            echo "END Your balance is: $balance Rwf (also sent via SMS)";
        }
    }

    public function menuWithdrawMoney($textArray) {
        $level = count($textArray);

        if ($level == 1) {
            echo "CON Enter amount";
        } elseif ($level == 2) {
            echo "CON Enter agent phone number";
        } elseif ($level == 3) {
            echo "CON Enter your PIN";
        } elseif ($level == 4) {
            list(, $amount, $agentPhone, $pin) = $textArray;

            echo "CON You are about to withdraw $amount Rwf via agent $agentPhone.\n";
            echo "1. Confirm\n2. Cancel\n3. Back\n4. Back to Main Menu";
        } elseif ($level == 5) {
            list(, $amount, $agentPhone, $pin, $option) = $textArray;
            $option = intval($option);

            if ($option === 1) {
                $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
                $stmt->execute([$this->phoneNumber]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($pin, $user['pin'])) {
                    echo "END Incorrect PIN.";
                    return;
                }

                if ($user['balance'] < $amount) {
                    echo "END Insufficient balance.";
                    return;
                }

                $stmt = $this->conn->prepare("SELECT * FROM agents WHERE phone_number = ?");
                $stmt->execute([$agentPhone]);
                $agent = $stmt->fetch();

                if (!$agent) {
                    echo "END Agent not found.";
                    return;
                }

                $this->conn->beginTransaction();

                $this->conn->prepare("UPDATE users SET balance = balance - ? WHERE phone_number = ?")
                    ->execute([$amount, $this->phoneNumber]);

                $this->conn->prepare("INSERT INTO transactions (sender_phone, amount, transaction_type, agent_phone) VALUES (?, ?, 'WITHDRAW', ?)")
                    ->execute([$this->phoneNumber, $amount, $agentPhone]);

                $this->conn->commit();

                // Send SMS notification
                $message = "You withdrew $amount Rwf via agent $agentPhone.";
                $this->sendSMSNotification($message);

                echo "END You have successfully withdrawn $amount Rwf via agent $agentPhone.";
            } elseif ($option === 2) {
                echo "END Transaction cancelled.";
            } else {
                echo "END Invalid option.";
            }
        }
    }

    public function menuDepositMoney($textArray) {
        $level = count($textArray);

        if ($level == 1) {
            echo "CON Enter deposit amount";
        } elseif ($level == 2) {
            echo "CON Enter agent phone number";
        } elseif ($level == 3) {
            echo "CON Enter your PIN";
        } elseif ($level == 4) {
            list(, $amount, $agentPhone, $pin) = $textArray;

            $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone_number = ?");
            $stmt->execute([$this->phoneNumber]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pin, $user['pin'])) {
                echo "END Incorrect PIN.";
                return;
            }

            $stmt = $this->conn->prepare("SELECT * FROM agents WHERE phone_number = ?");
            $stmt->execute([$agentPhone]);
            $agent = $stmt->fetch();

            if (!$agent) {
                echo "END Agent not found.";
                return;
            }

            $this->conn->beginTransaction();

            $this->conn->prepare("UPDATE users SET balance = balance + ? WHERE phone_number = ?")
                ->execute([$amount, $this->phoneNumber]);

            $this->conn->prepare("INSERT INTO transactions (recipient_phone, amount, transaction_type, agent_phone) VALUES (?, ?, 'DEPOSIT', ?)")
                ->execute([$this->phoneNumber, $amount, $agentPhone]);

            $this->conn->commit();

            // Send SMS notification
            $message = "Deposit of $amount Rwf successful via agent $agentPhone.";
            $this->sendSMSNotification($message);

            echo "END Deposit of $amount Rwf successful via agent $agentPhone.";
        }
    }

    public function goBack($text) {
        $explodedText = explode("*", $text);
        while (($index = array_search('98', $explodedText)) !== false && $index > 0) {
            array_splice($explodedText, $index - 1, 2);
        }
        return implode("*", $explodedText);
    }

    public function BackToMainMenu($text) {
        $explodedText = explode("*", $text);
        while (($index = array_search('99', $explodedText)) !== false && $index > 0) {
            array_splice($explodedText, $index - 1, 2);
        }
        return implode("*", $explodedText);
    }

    public function middleWare($text) {
        // Apply back and main menu logic if applicable
        if (strpos($text, '98') !== false) {
            return $this->goBack($text);
        } elseif (strpos($text, '99') !== false) {
            return $this->BackToMainMenu($text);
        }
        return $text;
    }
}
?>