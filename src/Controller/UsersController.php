<?php

namespace App\Controller;

use App\Controller\AppController;

use Cake\Mailer\TransportFactory;
use Cake\Mailer\MailerAwareTrait;
use Cake\Mailer\Email;
use Cake\Routing\Router;
use Cake\Event\Event;
use Cake\ORM\TableRegistry;
use Cake\Auth\DefaultPasswordHasher;
use Cake\Network\Exception\SocketException;

/**
 * Users Controller
 *
 * @property \App\Model\Table\UsersTable $Users
 *
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class UsersController extends AppController
{
    public function initialize()
    {
        parent::initialize();
        $this->Auth->allow(['logout', 'add', 'password', 'sendResetEmail', 'reset']);
        if (in_array($this->request->action, ['index', 'edit', 'delete', 'view']) && $this->Auth->user('role') == 'user') {
            $this->Flash->error(__('Sorry, you do not have access rights!'));
            return $this->redirect(['action' => 'login']);
        }
    }


    /**
     * Index method
     *
     * @return \Cake\Http\Response|null
     */
    public function index()
    {
        $users = $this->paginate($this->Users);

        $this->set(compact('users'));
    }

    /**
     * View method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $user = $this->Users->get($id, [
            'contain' => [],
        ]);

        $this->set('user', $user);
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $user = $this->Users->newEntity();
        if ($this->request->is('post')) {
            $user = $this->Users->patchEntity($user, $this->request->getData());
            $passwordDetails = $this->request->getData();
            if ($passwordDetails['password1'] == $passwordDetails['password2']) {
                $user->password = $passwordDetails['password1'];
                $user->role = 'user';
                $user->isApproved = 0;
                if ($this->Users->save($user)) {
                    //find all admin users
                    $allAdmin = $this->Users->find('all', ['conditions' => ['role' => 'admin']])->toList();
                    //var_dump($allAdmin);
                    for ($i = 0; $i < sizeof($allAdmin); $i++) {
                        $email = new Email('default');
                        $email->viewBuilder()->setTemplate('newuser');
                        $email->setEmailFormat('html');

                        $email->setTo($allAdmin[$i]["email"]);
                        $email->setSubject('New user registered');
                        $email->send();
                    }


                    $this->Flash->success(__('Congratulations!You have been registered to the system, please wait for admin to approve.'));

                    return $this->redirect(['action' => 'login']);
                } else {
                    $this->Flash->error(__('The user could not be saved. Please, try again.'));
                }
            } else {
                $this->Flash->error('Your confirm password is not same as your  password.');
            }
        }
        $this->set(compact('user'));
    }

    /**
     * Edit method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $user = $this->Users->get($id, [
            'contain' => [],
        ]);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $user = $this->Users->patchEntity($user, $this->request->getData());
            $user->role=$this->request->getData()["role"];
            if ($this->Users->save($user)) {
                $this->Flash->success(__('The user has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The user could not be saved. Please, try again.'));
        }
        $this->set(compact('user'));
    }

    /**
     * Delete method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        // $this->request->allowMethod(['post', 'delete']);
        $user = $this->Users->get($id);
        if ($this->Users->delete($user)) {
            $this->Flash->success(__('The user has been deleted.'));
        } else {
            $this->Flash->error(__('The user could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function reject($id = null)
    {
        // $this->request->allowMethod(['post', 'delete']);
        $user = $this->Users->get($id);
        if ($this->Users->delete($user)) {
            $this->Flash->success(__('The user has been rejected.'));
        } else {
            $this->Flash->error(__('The user could not be rejected. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function approve($id = null)
    {
        $user = $this->Users->get($id);
        $user->isApproved = 1;
        if ($this->Users->save($user)) {
            $email = new Email('default');
            $email->viewBuilder()->setTemplate('adminapproved');
            $email->setEmailFormat('html');

            $email->setTo($user["email"]);
            $email->setSubject('Your account have been approved.');
            $email->send();
            $this->Flash->success(__('The user has been approved.'));
            return $this->redirect(['action' => 'index']);
        }
    }

    public function login()
    {
        if ($this->request->is('post')) {
            $user = $this->Auth->identify();
            if ($user) {
                if (($user["isApproved"] == 1 && $user["role"] == "user") || $user["role"] == "admin") {
                    $this->Auth->setUser($user);
                    $this->Flash->success('You have successfully logged in.');
                    return $this->redirect($this->Auth->redirectUrl());
                } else {
                    $this->Flash->error('Please wait for admin approved.');
                }
            }
            $this->Flash->error('The email or password are not correct. Please, try again');
        }
    }




    public function logout()
    {
        $this->Flash->success('You are now logged out.');
        return $this->redirect($this->Auth->logout());
    }
    public function changePassword($userid = null)
    {
        $user = $this->Users->get($userid, [
            'contain' => []
        ]);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $passwordDetails = $this->request->getData();
            $hash = $user['password'];
            if (password_verify($passwordDetails['current'], $hash)) {
                if ($passwordDetails['new1'] == $passwordDetails['new2']) {
                    $user['password'] = $passwordDetails['new1'];
                    if ($this->Users->save($user)) {
                        $this->Flash->successDismiss(__('Your password has been updated.'));

                        return $this->redirect(['action' => 'view', $user->userID]);
                    }
                } else {
                    $this->Flash->error('Your confirm password is not same as your new password.');
                }
            } else {
                $this->Flash->error('Your current password is incorrect.');
            }
        }
        $this->set(compact('user'));
    }

    public function password()
    {
        $this->viewBuilder()->setLayout('auth');
        if ($this->getRequest()->is('post')) {
            $query = $this->Users->findByEmail($this->getRequest()->getdata('email'));

            $users = $query->first();

            if (is_null($users)) {
                $this->Flash->error('Email address does not exist. Please try again');
            } else {

                $passkey = uniqid();

                $url = Router::Url(['controller' => 'Users', 'action' => 'reset'], true) . '/' . $passkey;
                $timeout = time() + DAY;
                if ($this->Users->updateAll(['passkey' => $passkey, 'timeout' => $timeout], ['id' => $users->id])) {

                    $this->sendResetEmail($url, $users);
                    $this->redirect(['action' => 'login']);
                } else {
                    $this->Flash->error('Error saving reset passkey/timeout');
                }
            }
        }
    }

    private function sendResetEmail($url, $users)
    {
        $email = new Email('default');
        $email->viewBuilder()->setTemplate('resetpw');
        $email->setEmailFormat('both');

        $email->setTo($users->email);
        $email->setSubject('Reset your password');
        $email->setViewVars(['url' => $url]);
        // try {
        //     $email->send();
        //     // success
        // } catch (SocketException $exception) {
        //     var_dump($exception->getMessage());
        // }
        if ($email->send()) {
            $this->Flash->success(__('Check your email for your reset password link'));
        } else {
            $this->Flash->error(__('Error sending email: ') . $email->smtpError);
        }
    }

    public function reset($passkey = null)
    {
        $this->viewBuilder()->setLayout('auth');
        if ($passkey) {
            $query = $this->Users->find('all', ['conditions' => ['passkey' => $passkey, 'timeout >' => time()]]);
            $users = $query->first();
            if ($users) {
                if (!empty($this->getRequest()->getData())) {
                    // Clear passkey and timeout
                    $this->getRequest()->getdata('passkey') == null;
                    $this->getRequest()->getdata('timeout') == null;
                    $users = $this->Users->patchEntity($users, $this->getRequest()->getdata());


                    if ($this->Users->save($users)) {

                        $this->Flash->success(__('Your password has been successfully reset.'));
                        return $this->redirect(['action' => 'login']);
                    } else {
                        $this->Flash->error(__('Your password could not be reset. Please, try again.'));
                    }
                }
            } else {
                $this->Flash->error('Invalid or expired passkey. Please check your email or try again');
                $this->redirect(['action' => 'password']);
            }

            unset($users->password);
            $this->set(compact('users'));
        } else {
            $this->redirect('/');
        }
    }
}
