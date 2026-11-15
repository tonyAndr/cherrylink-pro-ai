# Publishing CherryLink on WordPress.org

The readme, Plugin Check (0 errors), translations, banner, icon and screenshots are ready.
The banner, icon and screenshots are in this folder; they go to SVN `assets/`, not into the zip.

## 1. Before you submit (once)

1. Log in or register at https://login.wordpress.org.
2. In `readme.txt`, replace `TODO_WPORG_USERNAME` with your WordPress.org username (or send it to Claude).
3. Make the source public: push this branch to GitHub. The readme's "Source code" section links
   to `github.com/tonyAndr/cherrylink-pro-ai`, and reviewers check that the
   `gutenberg-src/` there matches the built JS. Pushing a branch does not release anything
   to 1.x users; only a version tag does.
4. Deploy the free-plan backend first, because reviewers will click Connect. That means control
   `feature/free-plan`, API `feature/free-plan`, and the Traefik route
   `/cherrylink-pro-licensing/connect`. Ask Claude to do it.
5. Build the zip: `dev/build-org-zip.sh` → `dist/cherrylink.zip`.

## 2. Submit

1. Go to https://wordpress.org/plugins/developers/add/.
2. Upload `dist/cherrylink.zip`, tick the checkboxes and submit. The slug will be `cherrylink`.
3. Wait for the review email. This usually takes 1 to 3 weeks. If the reviewers ask for changes,
   forward the email to Claude, then reply to it with a fixed zip.

## 3. After approval: upload with SVN

1. Install SVN (`sudo dnf install subversion`). Set your SVN password under
   https://profiles.wordpress.org/ → Account & Security.
2. Check out the repository:
   `svn co https://plugins.svn.wordpress.org/cherrylink ~/cherrylink-svn`
3. Copy the files in and tag the version:
   `dev/svn-release.sh ~/cherrylink-svn`
4. Commit, using the command the script prints:
   `cd ~/cherrylink-svn && svn ci -m "Release 2.0.0" --username YOUR_USERNAME`
5. The page https://wordpress.org/plugins/cherrylink/ updates within about 15 minutes.

## Later releases

Bump `Version:` in `cherrylink_main.php` and `Stable tag:` in `readme.txt`, then repeat
steps 3.3 and 3.4. To change only the screenshots or banner, replace the PNGs here, run the same
script and commit.
