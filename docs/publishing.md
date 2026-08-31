# Publishing to GitHub

From the directory containing this repository:

    cd Drupal-Wordpress-Media-Migration
    git init
    git add .
    git commit -m "Initial public release"
    git branch -M main
    git remote add origin git@github.com:YOUR_ACCOUNT/Drupal-Wordpress-Media-Migration.git
    git push -u origin main

The ZIP archives in `dist/` are intentionally ignored by Git. Build them with:

    ./build-release.sh

Attach both ZIPs to a GitHub release instead of committing generated binaries.

Before publishing, replace the generic security contact instructions in
`SECURITY.md` with your preferred private reporting channel.
