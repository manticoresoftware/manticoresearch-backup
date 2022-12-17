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

%clean
rm -rf %{buildroot}

%post

%postun

%files
%doc usr/share/{{ NAME }}-README.md
%license usr/share/{{ NAME }}-LICENSE
%defattr(1755, root, root)
/usr/bin/{{ NAME }}
