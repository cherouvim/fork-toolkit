Update Project Documentation
============================

Use docker to update the documentation locally

The Docker image comes with all dependencies pre-installed.

To update the documentation run the following command. This will update
the content inside the folder ‘docs’.

```

   docker run --rm -v $(pwd):/data phpdoc/phpdoc run -d ./  -t ./docs
   
```

Then push the changes generated.

### Other topics
- [Available tasks](/docs/available-tasks.md)
- [Setting up a project](/docs/setting-up-project.md)
- [Configuring a project](/docs/configuring-project.md)
- [Installing the project](/docs/installing-project.md)
- [Testing the project](/docs/testing-project.md)
- [Using Docker environment](/docs/docker-environment.md)
- [Continuous integration](/docs/continuous-integration.md)
- [Building assets](/docs/building-assets.md)
- [Changelog](/changelog.md)
