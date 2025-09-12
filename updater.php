<?php
/**
 * Vortex GitHub Updater
 */

if ( ! class_exists( 'Vortex_GitHub_Updater' ) ) {
    class Vortex_GitHub_Updater {
        private $file;
        private $plugin;
        private $basename;
        private $username;
        private $repository;
        private $github_response;

        public function __construct( $file ) {
            $this->file       = $file;
            $this->plugin     = plugin_basename( $file );
            $this->basename   = dirname( $this->plugin );
            $this->username   = 'emkowale';     // your GitHub username
            $this->repository = 'vortex';       // your repo name

            add_filter( "pre_set_site_transient_update_plugins", [ $this, "check_update" ] );
            add_filter( "plugins_api", [ $this, "plugins_api" ], 10, 3 );
        }

        private function get_repo_release_info() {
            if ( ! empty( $this->github_response ) ) {
                return;
            }

            $request = wp_remote_get( "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest" );

            if ( is_wp_error( $request ) ) {
                return;
            }

            $this->github_response = json_decode( wp_remote_retrieve_body( $request ) );
        }

        public function check_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            $this->get_repo_release_info();

            if ( ! isset( $this->github_response->tag_name ) ) {
                return $transient;
            }

            $remote_version = ltrim( $this->github_response->tag_name, 'v' );
            $plugin_data    = get_plugin_data( $this->file );
            $local_version  = $plugin_data['Version'];

            if ( version_compare( $local_version, $remote_version, '<' ) ) {
                $package = $this->github_response->zipball_url;

                $transient->response[ $this->plugin ] = (object) [
                    'slug'        => $this->basename,
                    'plugin'      => $this->plugin,
                    'new_version' => $remote_version,
                    'package'     => $package,
                ];
            }

            return $transient;
        }

        public function plugins_api( $res, $action, $args ) {
            if ( 'plugin_information' !== $action || $args->slug !== $this->basename ) {
                return $res;
            }

            $this->get_repo_release_info();

            return (object) [
                'name'          => 'Vortex',
                'slug'          => $this->basename,
                'version'       => ltrim( $this->github_response->tag_name, 'v' ),
                'author'        => '<a href="https://github.com/emkowale">emkowale</a>',
                'homepage'      => "https://github.com/{$this->username}/{$this->repository}",
                'download_link' => $this->github_response->zipball_url,
            ];
        }
    }
}
