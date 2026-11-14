app_name=learning
build_dir=build
sign_dir=$(build_dir)/sign

all: build

.PHONY: build
build:
	npm install
	npm run build

.PHONY: clean
clean:
	rm -rf $(build_dir)
	rm -rf node_modules

.PHONY: release
# Same file set as RELEASE_SET in scripts/lifecycle-probe.sh. js/ and css/ come from the build;
# every other directory ships only git-tracked files, so gitignored local data (for example the
# copyright-sensitive starter-pool dump) can never reach the tarball. The version is read from
# appinfo/info.xml. This is a convenience build: the store release still follows the release
# checklist (signing in a throwaway Nextcloud) and must pass scripts/verify-release.sh.
version=$(shell sed -n 's:.*<version>\([0-9][^<]*\)</version>.*:\1:p' appinfo/info.xml | head -n 1)
release_tracked=appinfo img l10n lib templates data CHANGELOG.md LICENSE README.md
release: build
	@test -n "$(version)" || { echo "no <version> in appinfo/info.xml" >&2; exit 1; }
	mkdir -p $(build_dir)
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/$(app_name)
	cp -r js css $(build_dir)/$(app_name)/
	git ls-files -z -- $(release_tracked) | xargs -0 cp --parents -t $(build_dir)/$(app_name)/
	@for d in appinfo img l10n lib templates data; do \
		test -d $(build_dir)/$(app_name)/$$d || { echo "release: $$d/ missing from the package" >&2; exit 1; }; \
	done
	cd $(build_dir) && tar -czf $(app_name)-$(version).tar.gz $(app_name)
	@echo "Release tarball: $(build_dir)/$(app_name)-$(version).tar.gz"
