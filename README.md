# biodiv-action
A repository for the actions, forms, events and misc files on biologicaldiversity.org

Server directory: `/var/www/action`

NB: This is older code and has not been in source control. Because of this, there are secrets hard coded into some files which prevents them from being added to the repository. Over time, the secrets will be moved to configuration files and ignored by git.

At this time, the files on the server are **not** tracked in source control. This repository is to visibly track changes made to files in the even that they need to be reverted, but all changes on the server will happen outside of git. Expected changes are minor code fixes to reduce PHP warnings in the error logs.

In the future, as stability is tested and ensured, more files will be added into git so changes made by Jyn, Laurent or Beccy can be tracked. There is no plan to use git on the server for these files, as that will likely complicate changes made by our media teams who primarily use Filezilla or Dreamweaver.
