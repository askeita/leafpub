<?php
/**
 * Leafpub: Simple, beautiful publishing. (https://leafpub.org)
 *
 * @link      https://github.com/Leafpub/leafpub
 * @copyright Copyright (c) 2017 Leafpub Team
 * @license   https://github.com/Leafpub/leafpub/blob/master/LICENSE.md (GPL License)
 */

namespace Leafpub\Models;

use Leafpub\Leafpub,
    Leafpub\Theme,
    Leafpub\Renderer,
    Leafpub\Events\User\Add,
    Leafpub\Events\User\Added,
    Leafpub\Events\User\Update,
    Leafpub\Events\User\Updated,
    Leafpub\Events\User\Delete,
    Leafpub\Events\User\Deleted,
    Leafpub\Events\User\Retrieve,
    Leafpub\Events\User\Retrieved,
    Leafpub\Events\User\ManyRetrieve,
    Leafpub\Events\User\ManyRetrieved,
    Leafpub\Events\User\BeforeRender;

class User extends AbstractModel {
    protected static $_instance;
    /**
    * Constants
    **/
    const
        ALREADY_EXISTS = 1,
        CANNOT_CHANGE_OWNER = 2,
        CANNOT_DELETE_OWNER = 3,
        INVALID_EMAIL = 4,
        INVALID_NAME = 5,
        INVALID_PASSWORD = 6,
        INVALID_SLUG = 7,
        INVALID_USER = 8,
        NOT_FOUND = 9,
        PASSWORD_TOO_SHORT = 10,
        UNABLE_TO_ASSIGN_POSTS = 11;


    protected static function getModel(){
		if (self::$_instance == null){
			self::$_instance	=	new Tables\User();
		}
		return self::$_instance;
	}

    /**
    * Gets multiple users. Returns an array of tags on success, false if not found. If $pagination
    * is specified, it will be populated with pagination data generated by Leafpub::paginate().
    *
    * @param array $options
    * @param null &$pagination
    * @return mixed
    *
    **/
    public static function getMany(array $options = [], &$pagination = null){
        // Merge options with defaults
        $options = array_merge([
            'query' => null,
            'role' => null,
            'page' => 1,
            'items_per_page' => 10
        ], (array) $options);
        
        $model = self::getModel();

        $select = $model->getSql()->select();
        
        $where = function($wh) use ($options){
            $wh->nest->like('slug', '%' . $options['query'] . '%')
               ->or->like('name', '%' . $options['query'] . '%')
               ->or->like('email', '%' . $options['query'] . '%')
               ->or->like('bio', '%' . $options['query'] . '%')
               ->or->like('location', '%' . $options['query'] . '%')
               ->unnest();

            if($options['role']) {
                $wh->expression('FIND_IN_SET(role, ?) > 0', implode(',', (array) $options['role']));
            }
        };

        $select->where($where);

        $totalItems = self::count($where);

         $pagination = Leafpub::paginate(
            $totalItems,
            $options['items_per_page'],
            $options['page']
        );

        $offset = ($pagination['current_page'] - 1) * $pagination['items_per_page'];
        $count = $pagination['items_per_page'];
        
        $select->offset((int) $offset);
        $select->limit((int) $count);

        $select->order('name');

        $users = self::getModel()->selectWith($select)->toArray();

        foreach($users as $key => $value){
            $users[$key] = self::normalize($value);
        }
        return $users;
    }

    /**
    * Gets a single user. Returns an array on success, false if not found.
    *
    * @param String $slug
    * @return mixed
    *
    **/
    public static function getOne($user){
        $user = self::getModel()->select(['slug' => $user])->current();
        if (!$user) return false;
        return self::normalize($user->getArrayCopy());
    }

     /**
    * Creates a user
    *
    * @param array $user
    * @return bool
    * @throws \Exception
    *
    **/
    public static function create($user){
        $slug = $user['slug'];
        // Enforce slug syntax
        $slug = Leafpub::slug($slug);

        // Is the slug valid?
        if(!mb_strlen($slug) || Leafpub::isProtectedSlug($slug)) {
            throw new \Exception('Invalid slug: ' . $slug, self::INVALID_SLUG);
        }

        // Does a user already exist here?
        if(self::exists($slug)) {
            throw new \Exception('User already exists: ' . $slug, self::ALREADY_EXISTS);
        }

        // Must have a name
        if(!mb_strlen($user['name'])) {
            throw new \Exception('No name specified', self::INVALID_NAME);
        }

        // Must have a valid email address
        if(!Leafpub::isValidEmail($user['email'])) {
            throw new \Exception(
                'Invalid email address: ' . $user['email'],
                self::INVALID_EMAIL
            );
        }

        // Must have a long enough password
        if(mb_strlen($user['password']) < Setting::getOne('password_min_length')) {
            throw new \Exception(
                'Passwords must be at least ' . Setting::getOne('password_min_length') . ' characters long',
                self::PASSWORD_TOO_SHORT
            );
        }

        // Cannot create an owner if one already exists
        if($user['role'] === 'owner' && self::getOwner()) {
            throw new \Exception(
                'The owner role cannot be revoked or reassigned',
                self::CANNOT_CHANGE_OWNER
            );
        }

        // Don't allow null properties
        $user['reset_token'] = (string) $user['reset_token'];
        $user['bio'] = (string) $user['bio'];
        $user['cover'] = (string) $user['cover'];
        $user['avatar'] = (string) $user['avatar'];
        $user['twitter'] = (string) $user['twitter'];
        $user['location'] = (string) $user['location'];
        $user['website'] = (string) $user['website'];

        // Remove @ from Twitter handle
        $user['twitter'] = preg_replace('/@/', '', $user['twitter']);

        // Role must be owner, admin, or editor
        if(!in_array($user['role'], ['owner', 'admin', 'editor', 'author'])) {
            $user['role'] = 'author';
        }

        $evt = new Add($user);
        Leafpub::dispatchEvent(Add::NAME, $evt);
        $user = $evt->getEventData();

        // Hash the password
        $user['password'] = password_hash($user['password'], PASSWORD_DEFAULT);
        if($user['password'] === false) {
            throw new \Exception('Invalid password', self::INVALID_PASSWORD);
        }

        try {
            $ret = (self::getModel()->insert($user) > 0);
        } catch(\PDOException $e) {
            throw new \Exception('Database error: ' . $e->getMessage());
        }

        $evt = new Added($user);
        Leafpub::dispatchEvent(Added::NAME, $evt);

        return $ret;
    }

    /**
    * Updates a user
    *
    * @param array $properties
    * @return bool
    * @throws \Exception
    *
    **/
    public static function edit($properties){
        $slug = $properties['slug'];
        unset($properties['slug']);

        $evt = new Update($properties);
        Leafpub::dispatchEvent(Update::NAME, $evt);
        
        // Get the user
        $user = self::getOne(['slug' => $slug]);

        if(!$user) {
            throw new \Exception('User not found: ' . $slug, self::NOT_FOUND);
        }

        // The owner role cannot be revoked or reassigned
        if(
            isset($properties['role']) && (
                // Can't go from owner to non-owner
                ($user['role'] === 'owner' && $properties['role'] !== 'owner') ||
                // Can't go from non-owner to owner
                ($user['role'] !== 'owner' && $properties['role'] === 'owner')
            )
        ) {
            throw new \Exception(
                'The owner role cannot be revoked or reassigned',
                self::CANNOT_CHANGE_OWNER
            );
        }

        // Ignore the password property if a string wasn't passed in. This prevents the password
        // from being overwritten during array_merge().
        if(!is_string($properties['password'])) unset($properties['password']);

        // Merge properties
        $user = array_merge($user, $properties);

        // Must have a name
        if(!mb_strlen($user['name'])) {
            throw new \Exception('No name specified', self::INVALID_NAME);
        }

        // Must have an email address
        if(!Leafpub::isValidEmail($user['email'])) {
            throw new \Exception('Invalid email address: ' . $user['email'], self::INVALID_EMAIL);
        }

        // Don't allow null properties
        $user['reset_token'] = (string) $user['reset_token'];
        $user['bio'] = (string) $user['bio'];
        $user['cover'] = (string) $user['cover'];
        $user['avatar'] = (string) $user['avatar'];
        $user['twitter'] = (string) $user['twitter'];
        $user['location'] = (string) $user['location'];
        $user['website'] = (string) $user['website'];

        // Remove @ from Twitter handle
        $user['twitter'] = preg_replace('/@/', '', $user['twitter']);

        // Role must be owner, admin, or editor
        if(!in_array($user['role'], ['owner', 'admin', 'editor', 'author'])) {
            $user['role'] = 'author';
        }

        // Change the password?
        if(is_string($properties['password'])) {
            // Must have a long enough password
            if(mb_strlen($properties['password']) < Setting::getOne('password_min_length')) {
                throw new \Exception(
                    'Passwords must be at least ' . Setting::getOne('password_min_length') . ' characters long',
                    self::PASSWORD_TOO_SHORT
                );
            }

            // Hash the password
            $user['password'] = password_hash($properties['password'], PASSWORD_DEFAULT);
            if($user['password'] === false) {
                throw new \Exception('Invalid password', self::INVALID_PASSWORD);
            }
        }

        // Change the slug?
        if($user['slug'] !== $slug) {
            // Enforce slug syntax
            $user['slug'] = Leafpub::slug($user['slug']);

            // Is the slug valid?
            if(!mb_strlen($user['slug']) || Leafpub::isProtectedSlug($user['slug'])) {
                throw new \Exception('Invalid slug: ' . $user['slug'], self::INVALID_SLUG);
            }

            // Does a user already exist here?
            if(self::exists($user['slug'])) {
                throw new \Exception('User already exists: ' . $user['slug'], self::ALREADY_EXISTS);
            }
        }

        // Update the user
        try {
            $rowCount = self::getModel()->update($user, ['slug' => $slug]);
        } catch(\PDOException $e) {
            return false;
        }

        // Update session data for the authenticated user
        if(Session::user()['slug'] === $slug) {
            Session::update($user['slug']);
        }

        $evt = new Updated($user);
        Leafpub::dispatchEvent(Updated::NAME, $evt);

        return ($rowCount > 0);
    }

    /**
    * Deletes a user
    *
    * @param array $data
    * @return bool
    * @throws \Exception
    *
    **/
    public static function delete($data){
        $slug = $data['slug'];
        $recipient = $data['recipient'];

        $evt = new Delete($slug);
        Leafpub::dispatchEvent(Delete::NAME, $evt);

        // Get target user
        $user = self::getOne(['slug' => $slug]);
        if(!$user) throw new \Exception('Invalid user.', self::INVALID_USER);

        // Can't delete the owner
        if($user['role'] === 'owner') {
            throw new \Exception('Cannot delete the owner account.', self::CANNOT_DELETE_OWNER);
        }

        // Get the user that will receive the orphaned posts
        if($recipient) {
            // Use the specified recipient
            $recipient = self::getOne(['slug' => $recipient]);
        } else {
            // Use the owner
            $recipient = self::getOwner();
        }
        if(!$recipient) {
            throw new \Exception(
                'Invalid recipient: ' . $recipient['slug'],
                self::UNABLE_TO_ASSIGN_POSTS
            );
        }

        // Assign posts to new user
        try {
            Post::updateRecepient($user['id'], $recipient['id']);
        } catch(\PDOException $e) {
            throw new \Exception(
                'Unable to assign posts to new user: ' . $recipient['slug'],
                self::UNABLE_TO_ASSIGN_POSTS
            );
        }

        // Delete the target user
        try {
            $rowCount = self::getModel()->delete(function($where) use ($slug){
                            $where->equalTo('slug', $slug);
                            $where->notEqualTo('role', 'owner');
                        });

            $ret = ($rowCount() > 0);
        } catch(\PDOException $e) {
            return false;
        }

        $evt = new Deleted($slug);
        Leafpub::dispatchEvent(Deleted::NAME, $evt);

        return $ret;
    }
    
    /**
    * Normalize data types for certain fields
    *
    * @param array $user
    * @return array
    *
    **/
    private static function normalize($user) {
        // Cast to integer
        $user['id'] = (int) $user['id'];

        // Convert dates from UTC to local
        $user['created'] = Leafpub::utcToLocal($user['created']);

        return $user;
    }

    /**
    * Returns the total number of users that exist
    *
    * @return mixed
    *
    **/
    public static function count($where = null) {
        try {
            $model = self::getModel();
            $select = $model->getSql()->select()->columns(['num' => new \Zend\Db\Sql\Expression('COUNT(*)')]);
            if ($where !== null){
                $select->where($where);
            }
            $ret =  $model->selectWith($select);
            return $ret->current()['num'];
        } catch(\PDOException $e) {
            return false;
        }
    }

    /**
    * Tells whether a user exists
    *
    * @param String $slug
    * @return bool
    *
    **/
    public static function exists($slug) {
        try {
            $ret = self::getModel()->select(['slug' => $slug]);
            return !!$ret->current();
        } catch(\PDOException $e) {
            return false;
        }
    }

    /**
    * Converts a user slug to an ID
    *
    * @param String $slug
    * @return mixed
    *
    **/
    public static function getId($slug) {
        try {
            return (int) self::getOne($slug)['id'];
        } catch(\PDOException $e) {
            return false;
        }
    }

    /**
    * Returns an array of all user names and corresponding slugs
    *
    * @return mixed
    *
    **/
    public static function getNames() {
        try {
            $users = self::getModel()->selectWith(
                        self::getModel()->getSql()->select()
                            ->columns(['slug', 'name'])
                            ->order('name')
                     );
        } catch(\PDOException $e) {
            return false;
        }

        return $users;
    }

    /**
    * Gets the owner account
    *
    * @return mixed
    *
    **/
    public static function getOwner() {
        try {
            return self::getModel()->select(['role' => 'owner'])->current();
        } catch(\PDOException $e) {
            return false;
        }
    }

    /**
    * Returns an author (user) URL
    *
    * @param String $slug
    * @param int $page
    * @return String
    *
    **/
    public static function url($slug = '', $page = 1) {
        return $page > 1 ?
            // example.com/author/name/page/2
            Leafpub::url(
                Setting::getOne('frag_author'),
                $slug,
                Setting::getOne('frag_page'),
                $page
            ) :
            // example.com/author/name
            Leafpub::url(Setting::getOne('frag_author'), $slug);
    }

    /**
    * Verifies a user's password and returns true on success
    *
    * @param String $slug
    * @param String $password
    * @return bool
    *
    **/
    public static function verifyPassword($slug, $password) {
        // Get the user
        $user = self::getOne($slug);
        if(!$user) return false;

        // Verify the password
        return password_verify($password, $user['password']);
    }

    /**
    * Gets all available authors with their post count
    *
    * @param array $options
    * @return mixed
    *
    **/
    public static function getAuthors($options){
        $select = self::getModel()->getSql()->select();
        $select->columns([
            'a.slug',
            'a.name',
            'a.avatar',
            ['post_count' => new \Zend\Db\Sql\Expression('COALESCE(COUNT(*), 0)')]
        ])
        ->from(['a' => self::getModel()->getTable()])
        ->join(
            ['b' => Tables\TableGateway::$prefix . 'posts'],
            'a.id = b.author',
            '',
            'left'
        )
        ->order('a.slug')
        ->order('post_count');

        if (isset($options['query'])){
            $select->having('a.slug', '%' . $options['query'] . '%', 'OR')
                   ->having('a.name', '%' . $options['query'] . '%', 'OR');
        }
        
        try {   
            $authors = self::getModel()->selectWith($select);
        }
        catch(\PDOException $e) {
            return false;
        }
        return $authors;
    }

    /**
    * Renders an author page
    *
    * @param String $slug
    * @param int $page
    * @return mixed
    *
    **/
    public static function render($slug, $page = 1) {
        // Get the author
        $author = self::getOne($slug);
        if(!$author) return false;

        // Get the author's posts
        $posts = Post::getMany([
            'author' => $slug,
            'page' => $page,
            'items_per_page' => Setting::getOne('posts_per_page')
        ], $pagination);

        // Make sure the requested page exists
        if($page > $pagination['total_pages']) return false;

        // Add previous/next links to pagination
        $pagination['next_page_url'] = $pagination['next_page'] ?
            self::url($slug, $pagination['next_page']) : null;
        $pagination['previous_page_url'] = $pagination['previous_page'] ?
            self::url($slug, $pagination['previous_page']) : null;
        
        $beforeRender = new BeforeRender([
            'author' => $author,
            'special_vars' => [
                'meta' => [
                    'title'=> $author['name'],
                    'description' => Leafpub::getChars($author['bio'], 160),
                    // JSON linked data (schema.org)
                    'ld_json' => [
                        '@context' => 'https://schema.org',
                        '@type' => 'Person',
                        'name' => $author['name'],
                        'description' => strip_tags(Leafpub::markdownToHtml($author['bio'])),
                        'url' => self::url($author['slug']),
                        'image' => !empty($author['avatar']) ?
                                [
                                    '@type' => 'ImageObject',
                                    'url' => Leafpub::url($author['avatar'])
                                ] : null,
                        'sameAs' => !empty($author['website']) ?
                            [$author['website']] : null
                    ],
                    // Open Graph
                    'open_graph' => [
                        'og:type' => 'profile',
                        'og:site_name' => Setting::getOne('title'),
                        'og:title' => $author['name'] . ' &middot; ' . Setting::getOne('title'),
                        'og:description' => strip_tags(Leafpub::markdownToHtml($author['bio'])),
                        'og:url' => self::url($author['slug']),
                        'og:image' => !empty($author['avatar']) ?
                            Leafpub::url($author['avatar']) : null
                    ],
                    // Twitter Card
                    'twitter_card' => [
                        'twitter:card' => !empty($author['cover']) ?
                            'summary_large_image' : 'summary',
                        'twitter:site' => !empty(Setting::getOne('twitter')) ?
                            '@' . Setting::getOne('twitter') : null,
                        'twitter:title' => $author['name'] . ' &middot; ' . Setting::getOne('title'),
                        'twitter:description' => strip_tags(Leafpub::markdownToHtml($author['bio'])),
                        'twitter:creator' => !empty($author['twitter']) ?
                            '@' . $author['twitter'] : null,
                        'twitter:url' => self::url($author['slug']),
                        'twitter:image' => !empty($author['cover']) ?
                            Leafpub::url($author['cover']) : null
                    ]
                ]
            ]
        ]);

        Leafpub::dispatchEvent(BeforeRender::NAME, $beforeRender);
        $data = $beforeRender->getEventData();

        // Render it
        return Renderer::render([
            'template' => Theme::getPath('author.hbs'),
            'data' => [
                'author' => $data['author'],
                'posts' => $posts,
                'pagination' => $pagination
            ],
            'special_vars' => $data['special_vars'],
            'helpers' => ['url', 'utility', 'theme']
        ]);
    }
}