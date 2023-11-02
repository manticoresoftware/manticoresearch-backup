%define debug_package %{nil}

Summary: {{ DESC }}
Name: {{ NAME }}
Version: {{ VERSION }}
Release: 1%{?dist}
Group: Applications
License: PHP-3.01
Packager: {{ MAINTAINER }}
Vendor: {{ MAINTAINER }}

Source: tmp.tar.gz
BuildRoot: %{_tmppath}/%{name}-%{version}-buildroot
BuildArch: noarch

%description
{{ DESC }}

%prep
rm -rf %{buildroot}

%setup -n %{name}

%build

%install
mkdir -p %{buildroot}/usr/bin
cp -p usr/bin/{{ NAME }} %{buildroot}/usr/bin/
mkdir -p %{buildroot}/usr/share/manticore/modules
cp -rp usr/share/manticore/modules/{{ NAME }} %{buildroot}/usr/share/manticore/modules/{{ NAME }}

%clean
rm -rf %{buildroot}

%post

%postun

%files
%defattr(-, root, root)
%dir /usr/share/manticore/modules/{{ NAME }}
/usr/share/manticore/modules/{{ NAME }}/src/*
/usr/share/manticore/modules/{{ NAME }}/vendor/*
/usr/share/manticore/modules/{{ NAME }}/APP_VERSION
/usr/share/manticore/modules/{{ NAME }}/composer.json
/usr/share/manticore/modules/{{ NAME }}/composer.lock
%doc /usr/share/manticore/modules/{{ NAME }}/README.md
%license /usr/share/manticore/modules/{{ NAME }}/LICENSE
%attr(1755, root, root) /usr/bin/{{ NAME }}
