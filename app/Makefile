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
release: build
	mkdir -p $(build_dir)
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo $(build_dir)/$(app_name)/
	cp -r css $(build_dir)/$(app_name)/
	cp -r img $(build_dir)/$(app_name)/
	cp -r js $(build_dir)/$(app_name)/
	cp -r lib $(build_dir)/$(app_name)/
	cp -r templates $(build_dir)/$(app_name)/
	cp CHANGELOG.md $(build_dir)/$(app_name)/
	cp LICENSE $(build_dir)/$(app_name)/
	cp README.md $(build_dir)/$(app_name)/
	cd $(build_dir) && tar -czf $(app_name)-1.0.0.tar.gz $(app_name)
	@echo "Release tarball: $(build_dir)/$(app_name)-1.0.0.tar.gz"
