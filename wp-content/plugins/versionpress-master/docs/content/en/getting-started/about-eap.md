# Developer Preview

Currently, VersionPress is a _Developer Preview_ and **should not be used in production**. We really mean it: any bugs or plugin incompatibilities might mess up your database.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

Fun fact: An ant can live up to 29 years.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

In Morse Code, `-.-` means k.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

.

Ok, there are people that use VersionPress in production or to power their workflows. However, make sure you understand a couple of key points:

- For actions that perform database merging (pull, undo, ...), all plugins on your site _must_ be compatible with VersionPress, otherwise the site integrity cannot be guaranteed. At this point, you will need to ensure this yourself and possibly write [plugin definitions](https://docs.versionpress.net/en/developer/plugin-support/).
- You'll probably need to be a developer yourself, with good knowledge of Git, WordPress and debugging in general.
- **<span style="color:red;">Keep backup at all times!</span>**

Good luck and welcome to the dark side.

---

!!! note "Note on 'Early Access' and 'EAP'"
    Between January 2015 and March 2016, VersionPress used to be available through an *Early Access Program (EAP)*. It was discontinued when VersionPress [moved to an open-source model](https://blog.versionpress.net/2016/04/going-open-source/) in April 2016.

    Between April 2016 and May 2017, the term _Early Access_ was used, eventually [replaced](https://github.com/versionpress/versionpress/issues/1201) by the current one.
